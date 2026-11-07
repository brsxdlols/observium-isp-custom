#!/usr/bin/env python3
"""Ingest locally generated RIB/PPPoE JSON snapshots into Observium ISP data files.
This is NOT a router/SNMP collector. Requires explicitly configured sources.
"""
import argparse
import json
import os
import sqlite3
import sys
import tempfile
import time
from pathlib import Path

BASE = Path('/opt/observium')

def integer(value, label, positive=False):
    if type(value) is not int or value < (1 if positive else 0):
        raise ValueError(label + ': invalid integer')
    return value

def timestamp(value, now):
    integer(value, 'timestamp')
    if not now - 3600 <= value <= now + 120:
        raise ValueError('stale/future timestamp')
    return value

def read_json(path):
    if not isinstance(path, str) or not path.startswith('/'):
        raise ValueError('source path must be absolute')
    p = Path(path)
    if not p.is_file() or p.is_symlink() or p.stat().st_size > 1048576:
        raise ValueError('missing, symlink or oversized source: ' + path)
    return json.loads(p.read_text(encoding='utf-8'))

def write_atomic(path, data):
    path.parent.mkdir(parents=True, exist_ok=True)
    fd, tmp = tempfile.mkstemp(prefix='.snapshot-', dir=str(path.parent))
    try:
        with os.fdopen(fd, 'w', encoding='utf-8') as f:
            json.dump(data, f, ensure_ascii=False)
            f.write('\n')
            f.flush()
            os.fsync(f.fileno())
        os.chmod(tmp, 0o644)
        os.replace(tmp, path)
    finally:
        if os.path.exists(tmp):
            os.unlink(tmp)

def validate_rib(data, now):
    if not isinstance(data, dict):
        raise ValueError('RIB input must be object')
    return {'timestamp': timestamp(data.get('timestamp'), now),
            'ipv4_entries': integer(data.get('ipv4_entries'), 'ipv4_entries')}

def validate_pppoe(data, now):
    if not isinstance(data, dict) or not isinstance(data.get('devices'), list) or not data['devices']:
        raise ValueError('PPPoE input must have nonempty devices array')
    out, seen = [], set()
    for d in data['devices']:
        if not isinstance(d, dict):
            raise ValueError('invalid PPPoE device')
        id_ = integer(d.get('device_id'), 'device_id', True)
        if id_ in seen:
            raise ValueError('duplicate device_id')
        seen.add(id_)
        r = {'device_id': id_,
             'online': integer(d.get('online'), 'online'),
             'timestamp': timestamp(d.get('timestamp'), now)}
        for key in ('name', 'hostname', 'source'):
            value = d.get(key, 'configured' if key == 'source' else None)
            if not isinstance(value, str) or not value.strip() or len(value) > 255:
                raise ValueError('invalid ' + key)
            r[key] = value
        out.append(r)
    return out

def save_pppoe(rows, legacy_id):
    dest = BASE / 'isp-pppoe'
    dest.mkdir(parents=True, exist_ok=True)
    db = sqlite3.connect(str(dest / 'history-multi.sqlite'), timeout=10)
    try:
        db.execute('PRAGMA busy_timeout=10000')
        db.execute('CREATE TABLE IF NOT EXISTS devices (device_id INTEGER PRIMARY KEY, name TEXT NOT NULL, hostname TEXT NOT NULL, source TEXT NOT NULL)')
        db.execute('CREATE TABLE IF NOT EXISTS samples (device_id INTEGER NOT NULL, timestamp INTEGER NOT NULL, online INTEGER NOT NULL, PRIMARY KEY(device_id, timestamp))')
        for table, fields in [('devices', {'device_id','name','hostname','source'}),
                              ('samples', {'device_id','timestamp','online'})]:
            existing = {r[1] for r in db.execute('PRAGMA table_info(' + table + ')')}
            if not fields.issubset(existing):
                raise ValueError('incompatible existing SQLite schema: ' + table)
        with db:
            for d in rows:
                db.execute('INSERT INTO devices(device_id,name,hostname,source) VALUES(?,?,?,?) ON CONFLICT(device_id) DO UPDATE SET name=excluded.name,hostname=excluded.hostname,source=excluded.source',
                           (d['device_id'], d['name'], d['hostname'], d['source']))
                db.execute('INSERT OR REPLACE INTO samples(device_id,timestamp,online) VALUES(?,?,?)',
                           (d['device_id'], d['timestamp'], d['online']))
            db.execute('DELETE FROM samples WHERE timestamp < ?', (int(time.time()) - 90*86400,))
    finally:
        db.close()
    if legacy_id:
        row = next((d for d in rows if d['device_id'] == legacy_id), None)
        if row:
            write_atomic(dest / 'dashboard.json', {key: row[key] for key in ('device_id','hostname','timestamp','online')})

def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--config', default='/etc/observium-isp/sources.json')
    ap.add_argument('--check', action='store_true')
    args = ap.parse_args()
    cfg_path = Path(args.config)
    if not cfg_path.is_file():
        print('NOT CONFIGURED: /etc/observium-isp/sources.json absent')
        return 0 if args.check else 2
    cfg = json.loads(cfg_path.read_text(encoding='utf-8'))
    if not isinstance(cfg, dict) or not any(cfg.get(k) for k in ('rib_source', 'pppoe_source')):
        raise ValueError('configure rib_source or pppoe_source')
    now = int(time.time())
    rib = validate_rib(read_json(cfg['rib_source']), now) if cfg.get('rib_source') else None
    pppoe = validate_pppoe(read_json(cfg['pppoe_source']), now) if cfg.get('pppoe_source') else None
    legacy_id = cfg.get('legacy_device_id')
    if legacy_id is not None:
        legacy_id = integer(legacy_id, 'legacy_device_id', True)
    if args.check:
        print('CHECK OK: rib=%s pppoe_devices=%d; no writes' % (rib is not None, len(pppoe or [])))
        return 0
    if rib:
        write_atomic(BASE / 'isp-rib' / 'rib.json', rib)
    if pppoe:
        save_pppoe(pppoe, legacy_id)
    print('COLLECTED: rib=%s pppoe_devices=%d' % (rib is not None, len(pppoe or [])))
    return 0

if __name__ == '__main__':
    try:
        sys.exit(main())
    except (ValueError, OSError, sqlite3.Error, json.JSONDecodeError) as e:
        print('ERROR: ' + str(e), file=sys.stderr)
        sys.exit(1)
