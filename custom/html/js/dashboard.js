/* Dashboard JS extracted from html/pages/dashboard.inc.php
 * Provides initialization and handlers for the grid, widgets, and actions.
 */

function ObserviumDashboardInit(options) {
  // options: { cellHeight, hMargin, vMargin, isEditing, dashId, slug, initialGrid, requesttoken, widgetDefaults, redirectUrl }
  var gridObj;

  function isNumber(n) { return !isNaN(parseFloat(n)) && isFinite(n); }

  // Determine current dashboard id reliably in view and edit modes
  function currentDashId() {
    var v = $('#dash_id').val();
    if (v && v !== 'undefined') { return v; }
    if (options && isNumber(options.dashId)) { return options.dashId; }
    var sel = $('#dashboard_picker').val();
    if (sel && sel !== 'undefined') { return sel; }
    return null;
  }

  function ajaxWithToken(url, data, success) {
    data = data || {};
    if (options.requesttoken) { data.requesttoken = options.requesttoken; }
    return $.ajax({ type: 'POST', url: url, data: $.param(data), cache: false, success: success, error: function(xhr, status, err){
      var message = (xhr && xhr.responseJSON && xhr.responseJSON.message) ? xhr.responseJSON.message : (xhr && xhr.responseText ? xhr.responseText : (status + ': ' + err));
      showAlert('Request failed: ' + message, 'danger');
      if (window.console) { console.error('AJAX error:', url, status, err); }
    }});
  }

  function showAlert(msg, type) {
    type = type || 'warning';
    var $c = $('#dashboard-alerts');
    if (!$c.length) { $('body').prepend('<div id=\"dashboard-alerts\"></div>'); $c = $('#dashboard-alerts'); }
    var $a = $('<div class=\"alert alert-' + type + '\" role=\"alert\" style=\"margin-bottom:8px;\">' + $('<div>').text(msg).html() + '</div>');
    $c.prepend($a);
    setTimeout(function(){ $a.fadeOut(300, function(){ $(this).remove(); }); }, 5000);
  }

  $(function () {
    var gridstackOptions = {
      cellHeight: options.cellHeight,
      horizontalMargin: options.hMargin,
      verticalMargin: options.vMargin,
      resizable: {
        autoHide: true,
        handles: options.isEditing ? 'se, sw' : 'none'
      },
      draggable: {
        handle: '.drag-handle'
      }
    };
    $('.grid-stack').gridstack(gridstackOptions);

    gridObj = $('.grid-stack').data('gridstack');


    // ISP_DASHBOARD_HORIZONTAL_FIX_V1
    (function () {
      var style = document.createElement('style');
      style.textContent =
        '.grid-stack > .grid-stack-item > .grid-stack-item-content {' +
        'overflow-x:hidden !important;' +
        'overflow-y:visible !important;' +
        'margin-right:0 !important;' +
        '}' +
        '.grid-stack .isp-autoheight-box {' +
        'max-width:100%;min-width:0;' +
        '}';
      document.head.appendChild(style);
    })();

    // ISP_DASHBOARD_AUTOHEIGHT_V6
    (function () {
      var adjusting = false;
      var scheduled = false;

      function syncHeights() {
        if (adjusting || !gridObj) return;

        var grid = document.querySelector('.grid-stack');
        if (!grid) return;

        adjusting = true;
        window.ISPGridAutoResizing = true;

        try {
          // ISP_DASHBOARD_CELLHEIGHT_DOM_V7
          // Calcula a altura real da linha pelo tamanho renderizado.
          var cellHeight = 0;
          var sample = grid.querySelector('.grid-stack-item');

          if (sample) {
            var sampleNode = jQuery(sample).data('_gridstack_node');

            if (sampleNode && sampleNode.height > 0) {
              cellHeight =
                sample.getBoundingClientRect().height /
                sampleNode.height;
            }
          }

          if (!isFinite(cellHeight) || cellHeight <= 0) {
            cellHeight = Number(options.cellHeight) || 40;
          }

          var widgets = Array.prototype.slice.call(
            grid.querySelectorAll('.grid-stack-item')
          );

          widgets.forEach(function (el) {
            var box = el.querySelector('.isp-autoheight-box, .isp-rib-pppoe-box');
            var node = jQuery(el).data('_gridstack_node');

            if (!box || !node) return;

            if (
              box.classList.contains('isp-bgp-box') ||
              box.classList.contains('isp-rib-pppoe-box')
            ) {
              return;
            }

            var content = el.querySelector('.grid-stack-item-content');
            if (!content) return;

            // Mede o conteudo real, sem usar a altura do GridStack.
            var previousHeight = box.style.height;
            var previousMinHeight = box.style.minHeight;

            box.style.height = 'auto';
            box.style.minHeight = '0';

            var rect = box.getBoundingClientRect();
            var requiredPx = Math.ceil(rect.height + 16);

            box.style.height = previousHeight;
            box.style.minHeight = previousMinHeight;

            if (!isFinite(requiredPx) || requiredPx <= 0) return;

            var requiredRows = Math.max(
              4,
              Math.ceil(requiredPx / cellHeight)
            );

            if (node.height !== requiredRows) {
              gridObj.resize(el, node.width, requiredRows);
            }
          });


          // ISP_TOP_WIDGETS_EQUAL_HEIGHT_V1
          // BGP e RIB/PPPoE compartilham a mesma altura.
          // A medicao ignora a altura reservada anteriormente.

          var topPanels = [
            {id: 6, selector: '.isp-bgp-box'},
            {id: 9, selector: '.isp-rib-pppoe-box'}
          ];

          var topItems = [];
          var maxTopHeight = 0;

          topPanels.forEach(function (panel) {
            var el = grid.querySelector(
              '.grid-stack-item[data-gs-id="' + panel.id + '"]'
            );

            if (!el) return;

            var node = jQuery(el).data('_gridstack_node');
            var box = el.querySelector(panel.selector);

            if (!node || !box) return;

            var oldHeight = box.style.height;
            var oldMinHeight = box.style.minHeight;

            box.style.height = 'auto';
            box.style.minHeight = '0';

            var naturalHeight = Math.ceil(
              box.getBoundingClientRect().height
            );

            box.style.height = oldHeight;
            box.style.minHeight = oldMinHeight;

            if (!isFinite(naturalHeight) || naturalHeight <= 0) return;

            maxTopHeight = Math.max(maxTopHeight, naturalHeight);

            topItems.push({
              el: el,
              node: node,
              box: box
            });
          });

          if (topItems.length === 2 && maxTopHeight > 0) {
            var topRows = Math.max(
              4,
              Math.ceil((maxTopHeight + 20) / cellHeight)
            );

            topItems.forEach(function (item) {
              if (item.node.height !== topRows) {
                gridObj.resize(
                  item.el,
                  item.node.width,
                  topRows
                );
              }

              // Estende a moldura ate a altura comum,
              // preservando uma pequena folga inferior.
              item.box.style.minHeight =
                Math.max(0, Math.floor(topRows * cellHeight - 12)) + 'px';
            });
          }

          // Reposiciona os widgets na sequencia original.
          var order = [4, 5, 8];
          var cursor = 0;

          widgets.forEach(function (el) {
            var id = Number(el.getAttribute('data-gs-id'));
            var node = jQuery(el).data('_gridstack_node');

            if (node && (id === 6 || id === 9)) {
              cursor = Math.max(cursor, node.y + node.height);
            }
          });

          order.forEach(function (id) {
            var el = grid.querySelector(
              '.grid-stack-item[data-gs-id="' + id + '"]'
            );
            if (!el) return;

            var node = jQuery(el).data('_gridstack_node');
            if (!node) return;

            // ISP_STATUS_COMPACT_STRIP_V2
            // Widget 4 antes dos links.
            // Altura fixa com rolagem interna.

            if (id === 4) {
              if (node.width !== 12 || node.height !== 7) {
                gridObj.resize(el, 12, 7);
              }
            }

            if (node.y !== cursor || node.x !== 0) {
              gridObj.move(el, 0, cursor);
            }

            cursor += (id === 4 ? 7 : node.height);
          });

        } finally {
          adjusting = false;
          window.setTimeout(function () {
            window.ISPGridAutoResizing = false;
          }, 1000);
        }
      }

      // ISP_DASHBOARD_STABILITY_V1
      // Agrupa alteracoes rapidas do GridStack.
      // Limite de espera evita adiamento indefinido.

      var ispStableTimer = null;
      var ispStableFirstRequest = 0;

      function scheduleSync() {
        var now = Date.now();

        if (!ispStableFirstRequest) {
          ispStableFirstRequest = now;
        }

        if (ispStableTimer !== null) {
          window.clearTimeout(ispStableTimer);
        }

        var elapsed = now - ispStableFirstRequest;
        var delay = Math.max(0, Math.min(350, 1000 - elapsed));

        ispStableTimer = window.setTimeout(function () {
          ispStableTimer = null;
          ispStableFirstRequest = 0;

          // Mantem a rotina original de sincronizacao,
          // incluindo BGP expandido e compactado.
          syncHeights();
        }, delay);
      }

      document.addEventListener('click', function (event) {
        if (
          event.target.closest('summary') ||
          event.target.closest('.isp-native-rt-toggle') ||
          event.target.closest('.isp-graph24')
        ) {
          scheduleSync();
          window.setTimeout(scheduleSync, 600);
        }
      });

      var observer = new MutationObserver(scheduleSync);
      var gridElement = document.querySelector('.grid-stack');

      if (gridElement) {
        observer.observe(gridElement, {
          childList: true,
          subtree: true
        });
      }

      window.ISPDashboardSyncHeights = scheduleSync;
      scheduleSync();
    })();



    ///////////////
    // LOAD GRID //
    ///////////////
    function loadGrid() {
      gridObj.removeAll();
      var items = GridStackUI.Utils.sort(options.initialGrid || []);
      _.each(items, function (node) {
        // ISP_HIDE_FOOTER_LEGEND_V1
        // Mantem widget no banco, mas nao monta no dashboard.
        if (Number(node.id) === 7) return;
        node.autoposition = null;
        drawWidget(node);
      });
      return false;
    }

    ///////////////
    // SAVE GRID //
    ///////////////
    function saveGrid() {
      var current = _.map($('.grid-stack > .grid-stack-item:visible'), function (el) {
        el = $(el);
        var node = el.data('_gridstack_node');
        return { x: node.x, y: node.y, width: node.width, height: node.height, id: el.attr('data-gs-id') };
      });
      $('#saved-data').val(JSON.stringify(current, null, '    '));

      ajaxWithToken('ajax/actions.php', { action: 'save_grid', grid: current });
      return false;
    }

    // Expose for other handlers
    window.ObserviumDashboardSave = saveGrid;

    /////////////////////
    // DRAW THE WIDGET //
    /////////////////////
    function drawWidget(node) {
      var controls = '';
      var widgetType = node.type || (node.widget_type ? node.widget_type : '');
      var meta = (options.widgetDefaults && widgetType && options.widgetDefaults[widgetType]) ? options.widgetDefaults[widgetType] : null;

      if (options.isEditing) {
        controls = '<div class="hover-show" style="z-index: 1000; position: absolute; top:0px; right: 10px; padding: 2px 10px; padding-right: 0px; border-bottom-left-radius: 4px; border: 1px solid #e5e5e5; border-right: none; border-top: none; background: white;">' +
          '  <i style="cursor: pointer; margin: 7px;" class="sprite-refresh" onclick="refreshWidget(' + node.id + ')"></i>' +
          '  <i style="cursor: pointer; margin: 7px;" class="sprite-tools" onclick="configWidget(' + node.id + ')"></i></i>' +
          '  <i style="cursor: no-drop; margin: 7px;" class="sprite-cancel" onclick="deleteWidget(' + node.id + ')"></i>' +
          '  <i style="cursor: move; margin: 7px; margin-right: 20px" class="sprite-move drag-handle"></i>' +
          '</div>';
      }

      var $wrapper = $('<div>').attr('data-widget-type', widgetType || '');
      var $content = $('<div>').attr('id', 'widget-' + node.id).addClass('grid-stack-item-content');
      $wrapper.append($content);
      if (controls) {
        $wrapper.append($(controls));
      }

      gridObj.addWidget($wrapper,
        node.x, node.y,
        node.width, node.height,
        node.autoposition,
        meta && typeof meta.minW !== 'undefined' ? meta.minW : null,
        meta && typeof meta.maxW !== 'undefined' ? meta.maxW : null,
        meta && typeof meta.minH !== 'undefined' ? meta.minH : null,
        meta && typeof meta.maxH !== 'undefined' ? meta.maxH : null,
        node.id);
    }

    ////////////////
    // ADD WIDGET //
    ////////////////
    window.addNewWidget = function (type, dash_id) {
      ajaxWithToken('ajax/actions.php', { action: 'add_widget', widget_type: type, dash_id: dash_id }, function (response) {
        if (isNumber(response.id)) {
          var def = (options.widgetDefaults && options.widgetDefaults[type]) ? options.widgetDefaults[type] : { w: 4, h: 3 };
          var node = { width: def.w, height: def.h, autoposition: true, id: response.id, type: type };
          drawWidget(node);
          saveGrid();
          refreshAllWidgets(response.id);
        }
      });
      return false;
    };

    /////////////////////////
    // Refresh All Widgets //
    /////////////////////////
    window.refreshAllWidgets = function () {
      $('.grid-stack-item').each(function () {
        refreshWidget($(this).attr('data-gs-id'));
      });
    };

    window.refreshAllUpdatableWidgets = function () {
      $('.grid-stack-item').each(function () {
        if (!$(this).children('div').children('div').hasClass('do-not-update') && !$(this).is(':hover')) {
          refreshWidget($(this).attr('data-gs-id'));
        }
      });
    };

    window.refreshAllUpdatableImages = function () {
      var pt = /\&nocache=\d+/;
      $('.image-refresh').each(function () {
        if (this.src) {
          pt.test(this.src) ? $(this).attr('src', this.src.replace(pt, '&nocache=' + Date.now())) : $(this).attr('src', this.src + '&nocache=' + Date.now());
        }
        if (this.srcset) {
          pt.test(this.srcset) ? $(this).attr('srcset', this.srcset.replace(pt, '&nocache=' + Date.now())) : $(this).attr('srcset', this.srcset.replace(/\ /, '&nocache=' + Date.now() + ' '));
        }
      });
    };

    ///////////////////////////
    // Refresh single widget //
    ///////////////////////////
    

    // ISP_TOP_WIDGETS_ALIGNMENT_V1
    (function () {
      var style = document.createElement('style');
      style.textContent =
        '.grid-stack-item:has(.isp-rib-pppoe-box) > .grid-stack-item-content {' +
          'overflow:hidden !important;' +
        '}' +
        '.isp-rib-pppoe-box {' +
          'margin:0 !important;' +
          'overflow:visible !important;' +
        '}';
      document.head.appendChild(style);
    })();

    // ISP_RIB_PPPOE_NO_SCROLL_V1
    (function () {
      var style = document.createElement('style');
      style.textContent =
        '.grid-stack-item:has(.isp-rib-pppoe-box) > .grid-stack-item-content {' +
          'overflow-y:hidden !important;' +
          'overflow-x:hidden !important;' +
        '}' +
        '.isp-rib-pppoe-box {' +
          'height:auto !important;' +
          'overflow:visible !important;' +
        '}';
      document.head.appendChild(style);
    })();

    // ISP_BGP_SCROLL_ONLY_ABOVE_TWO_V1
    (function () {
      var style = document.createElement('style');
      style.textContent =
        '.grid-stack-item:has(.isp-bgp-box) .grid-stack-item-content {' +
          'overflow-y:hidden !important;' +
        '}' +
        '.isp-bgp-box {' +
          'overflow-y:hidden !important;' +
        '}' +
        '.isp-bgp-box.isp-bgp-many-sessions {' +
          'overflow-y:auto !important;' +
        '}';
      document.head.appendChild(style);
    })();

    // ISP_BGP_EXPANDED_DEFAULT_V1
    // Expandido por padrao ao carregar a pagina.
    // O usuario pode recolher manualmente.
    window.ISPBGPExpanded = true;

    // ISP_BGP_INITIAL_EXPANSION_V1
    (function () {
      var observer = new MutationObserver(function () {
        var roots = document.querySelectorAll('.isp-bgp-box');

        roots.forEach(function (root) {
          var button = root.querySelector('.isp-bgp-toggle');

          if (
            button &&
            button.getAttribute('aria-expanded') !== 'true' &&
            window.ISPBGPExpanded === true &&
            typeof window.ispBGPToggle === 'function'
          ) {
            window.ispBGPToggle(button);
          }
        });
      });

      observer.observe(document.querySelector('.grid-stack'), {
        childList: true,
        subtree: true
      });
    })();

    // ISP_BGP_DEFAULT_OPEN_BOOT_V2
    // O estado só existe enquanto a página estiver aberta.
    if (typeof window.ISPBGPExpanded === 'undefined') {
      window.ISPBGPExpanded = true;
    }

    // ISP_BGP_TOGGLE_GLOBAL_V2
    window.ispBGPToggle = function(button) {
      var root = button.closest('.isp-bgp-box');
      if (!root) return false;

      // Habilita rolagem somente quando houver mais de 2 sessões.
      var sessionCount = root.querySelectorAll('.isp-bgp-peer').length;
      root.classList.toggle('isp-bgp-many-sessions', sessionCount > 2);

      // ISP_BGP_PERSIST_STATE_V1
      var expanded = button.getAttribute('aria-expanded') !== 'true';
      window.ISPBGPExpanded = expanded;

      // ISP_BGP_SHOW_ALL_COMPACT_V1
      // Resumido mostra TODOS os peers em 3 colunas.
      root.classList.toggle('isp-bgp-compact', !expanded);

      root.querySelectorAll('.isp-bgp-peer').forEach(function(peer) {
        peer.style.display = '';
      });

      var note = root.querySelector('.isp-bgp-compact-note');
      if (note) note.style.display = expanded ? 'none' : '';

      button.setAttribute('aria-expanded', expanded ? 'true' : 'false');
      button.textContent = expanded ? 'Resumir sessões' : 'Expandir sessões';

      if (typeof window.ISPDashboardSyncHeights === 'function') {
        window.ISPDashboardSyncHeights();
      }

      return false;
    };

    window.refreshWidget = function (id) {
      var div = $('#widget-' + id);
       // ISP_TRANSIT_PRESERVE_V1
       if (div.find('.isp-native-rt-panel').filter(function () {
           return this.style.display !== 'none';
       }).length) {
           return;
       }
      var params = { width: div.innerWidth(), height: div.innerHeight(), id: id };
      ajaxWithToken('ajax/widget.php', params, function (response) {

        // ISP_GRAPH24_AJAX_PERSIST_V1
        // Preserva graficos abertos somente durante a vida desta pagina.
        // F5 reinicia todos recolhidos.
        var graph24Open = new Set();

        div.find('details.isp-graph24[open][data-graph-url]').each(function () {
          graph24Open.add(this.getAttribute('data-graph-url'));
        });

        div.html(response);

        div.find('details.isp-graph24[data-graph-url]').each(function () {
          var graphUrl = this.getAttribute('data-graph-url');

          if (graph24Open.has(graphUrl)) {
            this.open = true;
            this.dataset.graph24Restored = '1';
          }
        });

        // Restaura o estado do BGP após atualização AJAX.
        // ISP_BGP_DEFAULT_EXPANDED_V1
        // Inicia expandido e permite recolher manualmente.
        if (typeof window.ISPBGPExpanded === 'undefined') {
          window.ISPBGPExpanded = true;
        }

        var bgp = div.find('.isp-bgp-box')[0];
        if (bgp) {
          var button = bgp.querySelector('.isp-bgp-toggle');

          if (button) {
            var shouldExpand = window.ISPBGPExpanded !== false;
            var currentlyExpanded =
              button.getAttribute('aria-expanded') === 'true';

            if (shouldExpand !== currentlyExpanded &&
                typeof window.ispBGPToggle === 'function') {
              window.ispBGPToggle(button);
            }
          }
        }
      });
    };

    window.deleteWidget = function (id) {
      var el = $(".grid-stack-item[data-gs-id='" + id + "']");
      ajaxWithToken('ajax/actions.php', { action: 'del_widget', widget_id: id }, function (response) {
        gridObj.removeWidget(el);
      });
    };

    window.configWidget = function (id) {
      ajaxWithToken('ajax/actions.php', { action: 'edit_widget', widget_id: id }, function (response) {
        $('#config-modal-body').html(response);

        // Initialize form controls after AJAX content is loaded
        setTimeout(function() {
          $('#config-modal-body .selectpicker').selectpicker();
          // Refresh selectpickers to ensure values are properly displayed
          $('#config-modal-body .selectpicker').selectpicker('refresh');
          $('#config-modal-body [data-toggle="tooltip"]').tooltip();
        }, 100);

        $('#config-modal').modal({ show: true });
      });
    };

    window.saveWidgetConfig = function (widget_id) {
      var config = {};

      // Collect all widget config fields
      $('#config-modal-body input[data-field], #config-modal-body select[data-field]').each(function() {
        var $field = $(this);
        var fieldName = $field.attr('data-field');
        var fieldType = $field.attr('data-type');
        var value = $field.val();

        // Handle multiselect arrays
        if (fieldType === 'multiselect' && Array.isArray(value)) {
          config[fieldName] = value;
        } else if (fieldType === 'checkbox') {
          config[fieldName] = $field.prop('checked') ? 'yes' : 'no';
        } else {
          config[fieldName] = value;
        }
      });

      // Send the configuration update
      ajaxWithToken('ajax/actions.php', {
        action: 'update_widget_config',
        widget_id: widget_id,
        config: config
      }, function (response) {
        if (response.status === 'ok') {
          $('#config-modal').modal('hide');
          // Refresh the widget if needed
          location.reload(); // Simple refresh for now
        } else {
          alert('Error saving configuration: ' + (response.message || 'Unknown error'));
        }
      });
    };

    window.dashDelete = function () {
      var dash_id = $('#dash_id').val();
      ajaxWithToken('ajax/actions.php', { action: 'dash_delete', dash_id: dash_id }, function (json) {
        if (json.status === 'ok') {
          var url = options.redirectUrl || 'dashboard/';
          window.setTimeout(function(){ window.location.href = url; }, 500);
        }
      });
    };

    // Set dashboard visibility (public/private)
    window.dashSetPublic = function () {
      var dash_id = $('#dash_id').val();
      var is_public = $('#dash_public').is(':checked');
      ajaxWithToken('ajax/actions.php', { action: 'dash_visibility', dash_id: dash_id, is_public: is_public ? 1 : 0 }, function (json) {
        // alerts handled centrally
      });
    };

    // Set default dashboard for current user
    window.dashSetDefault = function () {
      var dash_id = currentDashId();
      ajaxWithToken('ajax/actions.php', { action: 'dash_set_default', dash_id: dash_id }, function (json) {
        if (json.status === 'ok') { showAlert('Default dashboard updated.', 'success'); }
        else if (json && json.message) { showAlert(json.message, 'warning'); }
      });
    };
    // Export current dashboard to a JSON file
    window.dashExport = function(){
      var dash_id = currentDashId();
      if (!dash_id) { showAlert('Unable to determine dashboard id.', 'warning'); return; }
      ajaxWithToken('ajax/actions.php', { action: 'dash_export', dash_id: dash_id }, function (json) {
        if (json.status === 'ok' && json.payload) {
          var filenameSlug = null;
          try {
            var p = JSON.parse(json.payload);
            if (p && p.slug) { filenameSlug = p.slug; }
          } catch(e) { /* ignore */ }
          if (!filenameSlug && options && options.slug) { filenameSlug = options.slug; }
          var safeSlug = filenameSlug ? String(filenameSlug).toLowerCase().replace(/[^a-z0-9\-]+/g, '-') : null;
          var downloadName = 'dashboard-' + (safeSlug || dash_id || 'export') + '.json';
          var blob = new Blob([json.payload], {type: 'application/json'});
          var a = document.createElement('a');
          a.href = URL.createObjectURL(blob);
          a.download = downloadName;
          document.body.appendChild(a); a.click(); document.body.removeChild(a);
        } else if (json && json.message) {
          showAlert(json.message, 'warning');
        } else {
          showAlert('Export failed.', 'warning');
        }
      });
    };
    // Import dashboard JSON from user and create a new dashboard
    window.dashImport = function(){
      var input = $('<input type="file" accept="application/json" style="display:none;" />');
      $('body').append(input);
      input.on('change', function(){
        var file = this.files[0];
        if (!file) { input.remove(); return; }
        var reader = new FileReader();
        reader.onload = function(e){
          var payload = e.target.result;
          ajaxWithToken('ajax/actions.php', { action: 'dash_import', payload: payload }, function(json){
            if (json.status === 'ok' && (json.slug || json.id)) {
              var target = '?page=dashboard&dash=' + encodeURIComponent(json.slug || json.id) + '&edit=yes';
              window.location.href = target;
            } else if (json && json.message) {
              showAlert(json.message, 'warning');
            } else {
              showAlert('Import failed.', 'warning');
            }
          });
        };
        reader.readAsText(file);
        setTimeout(function(){ input.remove(); }, 1000);
      }).click();
    };

    // Update dashboard description
    $(document).on('change', '#dash_descr', function () {
      var dash_id = $('#dash_id').val();
      var descr = $('#dash_descr').val();
      ajaxWithToken('ajax/actions.php', { action: 'dash_update_descr', dash_id: dash_id, descr: descr });
    });

    // Clone current dashboard into a private copy for this user
    window.dashClone = function () {
      var dash_id = currentDashId();
      if (!dash_id) { showAlert('Unable to determine dashboard id.', 'warning'); return; }
      ajaxWithToken('ajax/actions.php', { action: 'dash_clone', dash_id: dash_id }, function (json) {
        if (json.status === 'ok' && (json.slug || json.id)) {
          var target = '?page=dashboard&dash=' + encodeURIComponent(json.slug || json.id) + '&edit=yes';
          window.location.href = target;
        } else if (json && json.message) {
          showAlert(json.message, 'warning');
        } else {
          showAlert('Clone failed.', 'warning');
        }
      });
    };

    // Dashboard picker change
    $(document).on('change', '#dashboard_picker', function(){
      var did = $(this).val();
      if (did) { window.location.href = '?page=dashboard&dash=' + did; }
    });

    // Enhanced reorder UI with better visual feedback
    window.dashReorderToggle = function(){
      var c = $('#dash-reorder');
      if (!c.length) { return; }
      c.slideToggle(200);
      
      try {
        if (!c.data('sortable-init-my')) {
          if ($('#dash-reorder-my-list').length) {
            $('#dash-reorder-my-list').sortable({ 
              handle: '.drag-handle',
              tolerance: 'pointer',
              placeholder: 'alert alert-warning',
              start: function(event, ui) {
                ui.placeholder.text('Drop here');
                ui.item.addClass('dragging');
              },
              stop: function(event, ui) {
                ui.item.removeClass('dragging');
                dashReorderSave('my');
              }
            });
          }
          c.data('sortable-init-my', true);
        }
        if (!c.data('sortable-init-public')) {
          if ($('#dash-reorder-public-list').length) {
            $('#dash-reorder-public-list').sortable({ 
              handle: '.drag-handle',
              tolerance: 'pointer', 
              placeholder: 'alert alert-warning',
              start: function(event, ui) {
                ui.placeholder.text('Drop here');
                ui.item.addClass('dragging');
              },
              stop: function(event, ui) {
                ui.item.removeClass('dragging');
                dashReorderSave('public');
              }
            });
          }
          c.data('sortable-init-public', true);
        }
      } catch(e) {
        console.error('Failed to initialize sortable:', e);
      }
    };
    
    window.dashReorderSave = function(scope){
      var listId = (scope === 'public') ? '#dash-reorder-public-list' : '#dash-reorder-my-list';
      if (!$(listId).length) { 
        showAlert('Nothing to reorder for ' + (scope || 'my') + '.', 'warning'); 
        return; 
      }
      var order = [];
      $(listId).children('.alert').each(function(){ 
        var dash = $(this).data('dash');
        if (dash) order.push(dash); 
      });
      if (order.length === 0) { return; }
      
      ajaxWithToken('ajax/actions.php', { action: 'dash_reorder', order: order, scope: scope || 'my' }, function(resp){
        if (resp && resp.status === 'ok') { 
          showAlert('Dashboard order saved automatically.', 'success'); 
        } else { 
          showAlert('Unable to save order.', 'warning'); 
        }
      });
    };

    // Actions hooks
    $("#dashboard_editor").submit(function (event) {
      if ($('#widget_type').val()) { addNewWidget($('#widget_type').val(), $('#dash_id').val()); }
      event.preventDefault();
    });

    $("#dash_name").change(function () {
      var dash_id = $('#dash_id').val();
      var dash_name = $('#dash_name').val();
      ajaxWithToken('ajax/actions.php', { action: 'dash_rename', dash_id: dash_id, dash_name: dash_name });
    });

    // Widget help text now shown as select subtext; no dynamic help needed

    // Init grid and first paint
    loadGrid();
    window.refreshAllWidgets();

    // Debounced save on changes; still refresh changed widgets immediately
    var saveGridDebounced = _.debounce(function(){
      if (window.ISPGridAutoResizing === true) return;
      saveGrid();
    }, 300);

    // ISP_GRIDSTACK_AUTOSAVE_GUARD_V5
    // Cancela salvamentos pendentes durante ajustes automaticos.
    var ispOriginalSaveGridDebounced = saveGridDebounced;
    saveGridDebounced = function () {
      if (window.ISPGridAutoResizing === true) return;
      return ispOriginalSaveGridDebounced();
    };
    $('.grid-stack').on('change', function (event, items) {
      // ISP_GRIDSTACK_CHANGE_GUARD_V6
      // Nao recarrega widgets durante redimensionamento automatico.
      if (window.ISPGridAutoResizing === true) return;
      if (items && items.forEach) {
        items.forEach(function (item) { refreshWidget(item.el.attr('data-gs-id')); });
      }
      saveGridDebounced();
    });
    $('.grid-stack').on('dragstop resizestop', function () { saveGridDebounced(); });


    // ISP_PPPOE_HOVER_IMEDIATO_V2
    // Delegacao: funciona mesmo apos atualizacao AJAX do widget.
    (function () {
      var tooltip = document.createElement('div');
      tooltip.id = 'isp-pppoe-tooltip-v2';
      tooltip.style.cssText =
        'position:fixed;display:none;z-index:99999;' +
        'pointer-events:none;background:#1e293b;color:white;' +
        'padding:8px 11px;border-radius:6px;' +
        'font-size:12px;font-weight:600;' +
        'box-shadow:0 3px 12px rgba(0,0,0,.2);' +
        'white-space:nowrap;';

      document.body.appendChild(tooltip);

      document.addEventListener('pointermove', function (event) {
        var target = event.target;
        var point = target && target.closest
          ? target.closest('[data-isp-pppoe-tooltip]')
          : null;

        if (!point) {
          tooltip.style.display = 'none';
          return;
        }

        var message = point.getAttribute(
          'data-isp-pppoe-tooltip'
        );

        if (!message) {
          tooltip.style.display = 'none';
          return;
        }

        tooltip.textContent = message;
        tooltip.style.display = 'block';

        var width = tooltip.offsetWidth;
        var height = tooltip.offsetHeight;

        var x = Math.min(
          event.clientX + 15,
          window.innerWidth - width - 12
        );

        var y = event.clientY - height - 13;

        if (y < 8) {
          y = event.clientY + 18;
        }

        tooltip.style.left = Math.max(8, x) + 'px';
        tooltip.style.top = Math.max(8, y) + 'px';
      });

      window.addEventListener('blur', function () {
        tooltip.style.display = 'none';
      });
    })();

    // Periodic refresh (skip when tab is hidden to reduce load)
    setInterval(function () {
      if (document.hidden) { return; }
      window.refreshAllUpdatableWidgets();
    }, 20000);
    setInterval(function () {
      if (document.hidden) { return; }
      window.refreshAllUpdatableImages();
    }, 15000);

    // Container resize refresh (debounced)
    var resizeDebounced = _.debounce(function(){ window.refreshAllWidgets(); }, 300);
    new ResizeSensor(jQuery('#main_container'), function () { resizeDebounced(); });
  });
}


// ISP_BGP_COMPACT_THREE_COLUMNS_V1
// Layout resumido: tres colunas, uma linha por peer.
(function () {
  if (document.getElementById('isp-bgp-compact-css-v1')) {
    return;
  }

  var style = document.createElement('style');
  style.id = 'isp-bgp-compact-css-v1';

  style.textContent = `
    .isp-bgp-box:not(.isp-bgp-compact)
      .isp-bgp-compact-line {
      display: none !important;
    }

    .isp-bgp-box.isp-bgp-compact
      .isp-bgp-grid {
      display: grid !important;
      grid-template-columns: repeat(3, minmax(0, 1fr)) !important;
      gap: 6px !important;
    }

    .isp-bgp-box.isp-bgp-compact
      .isp-bgp-peer {
      min-width: 0 !important;
      padding: 7px 8px !important;
      border-radius: 5px !important;
    }

    .isp-bgp-box.isp-bgp-compact
      .isp-bgp-peer > :not(.isp-bgp-compact-line) {
      display: none !important;
    }

    .isp-bgp-box.isp-bgp-compact
      .isp-bgp-compact-line {
      display: flex !important;
      align-items: center;
      justify-content: space-between;
      gap: 5px;
      min-width: 0;
      white-space: nowrap;
      font-size: 11px;
    }

    .isp-bgp-box.isp-bgp-compact
      .isp-bgp-compact-ip {
      overflow: hidden;
      text-overflow: ellipsis;
      min-width: 0;
      font-size: 11px;
    }

    .isp-bgp-box.isp-bgp-compact
      .isp-bgp-compact-state {
      flex-shrink: 0;
      font-size: 10px;
    }

    .isp-bgp-box.isp-bgp-compact
      .isp-bgp-compact-note {
      display: none !important;
    }

    @media (max-width: 700px) {
      .isp-bgp-box.isp-bgp-compact
        .isp-bgp-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
      }
    }
  `;

  document.head.appendChild(style);
})();


// ISP_STATUS_COMPACT_STYLE_V2
// Estilo exclusivo do widget 4 de notificacoes.

(function () {

  if (document.getElementById(
    'isp-status-compact-v2'
  )) {
    return;
  }

  var style = document.createElement('style');
  style.id = 'isp-status-compact-v2';

  style.textContent = `

    /* Container de notificacoes */

    .grid-stack-item[data-gs-id="4"]
    .grid-stack-item-content {
      overflow-x: hidden !important;
      overflow-y: auto !important;
      border: 1px solid #dce3e8 !important;
      border-radius: 7px !important;
      background: #fff !important;
      box-shadow: 0 1px 3px rgba(15,23,42,.06) !important;
      scrollbar-width: thin;
      scrollbar-color: #cbd5e1 transparent;
    }

    /* Evita margens e espacos excessivos */

    .grid-stack-item[data-gs-id="4"]
    .box,
    .grid-stack-item[data-gs-id="4"]
    .panel {
      margin: 0 !important;
      box-shadow: none !important;
      border: 0 !important;
    }

    /* Titulo compacto */

    .grid-stack-item[data-gs-id="4"]
    h3,
    .grid-stack-item[data-gs-id="4"]
    h4 {
      font-size: 13px !important;
      font-weight: 600 !important;
      margin: 0 !important;
    }

    /* Tabela de alertas */

    .grid-stack-item[data-gs-id="4"]
    table {
      width: 100% !important;
      margin-bottom: 0 !important;
      font-size: 12px !important;
    }

    .grid-stack-item[data-gs-id="4"]
    table td,
    .grid-stack-item[data-gs-id="4"]
    table th {
      padding: 6px 9px !important;
      vertical-align: middle !important;
    }

    /* Cabecalho de notificacoes */

    .grid-stack-item[data-gs-id="4"]
    .box-header,
    .grid-stack-item[data-gs-id="4"]
    .panel-heading {
      padding: 9px 12px !important;
      background: #f8fafc !important;
      border-bottom: 1px solid #e2e8f0 !important;
    }

    /* Alertas leves e compactos */

    .grid-stack-item[data-gs-id="4"]
    .alert {
      padding: 5px 9px !important;
      margin-bottom: 3px !important;
      font-size: 12px !important;
    }

  `;

  document.head.appendChild(style);

})();



// ISP_STATUS_MANAGER_STYLE_V1

(function () {
  if (document.getElementById(
    'isp-status-manager-style-v1'
  )) return;

  var style = document.createElement('style');
  style.id = 'isp-status-manager-style-v1';

  style.textContent = `

    .grid-stack-item[data-gs-id="4"]
    .box-header {
      display: flex !important;
      align-items: center !important;
      justify-content: space-between !important;
      gap: 12px;
    }

    .grid-stack-item[data-gs-id="4"]
    .isp-status-manager-button {
      border: 1px solid #cbd5e1;
      border-radius: 4px;
      background: #fff;
      color: #075985;
      font-size: 11px;
      padding: 4px 9px;
      cursor: pointer;
      flex-shrink: 0;
    }

    .grid-stack-item[data-gs-id="4"]
    .isp-status-manager-button:hover {
      background: #eff6ff;
    }

    .grid-stack-item[data-gs-id="4"]
    .isp-status-manager-panel {
      padding: 9px 12px;
      background: #f8fafc;
      border-bottom: 1px solid #e2e8f0;
    }

    .grid-stack-item[data-gs-id="4"]
    .isp-status-manager-panel[hidden] {
      display: none !important;
    }

    .grid-stack-item[data-gs-id="4"]
    .isp-status-manager-info {
      font-size: 11px;
      color: #64748b;
      margin-bottom: 7px;
    }

    .grid-stack-item[data-gs-id="4"]
    .isp-status-manager-list {
      max-height: 140px;
      overflow-y: auto;
      display: grid;
      grid-template-columns: repeat(
        auto-fit, minmax(190px, 1fr)
      );
      gap: 7px;
    }

    .grid-stack-item[data-gs-id="4"]
    .isp-status-manager-option {
      display: flex;
      align-items: center;
      gap: 7px;
      font-size: 11px;
      font-weight: 400;
      margin: 0;
      cursor: pointer;
    }

    .grid-stack-item[data-gs-id="4"]
    .isp-status-manager-restore {
      margin-top: 9px;
      padding: 4px 9px;
      border: 1px solid #cbd5e1;
      border-radius: 4px;
      background: #fff;
      font-size: 11px;
      cursor: pointer;
    }

    .grid-stack-item[data-gs-id="4"]
    .isp-status-device-name {
      font-weight: 700;
      color: inherit;
    }

  `;

  document.head.appendChild(style);
})();



// ISP_STATUS_MANAGER_V2
// Filtragem visual sem alterar alertas reais.
(function () {
  'use strict';

  if (window.ISPStatusManagerV2) return;
  window.ISPStatusManagerV2 = true;

  var KEY = 'isp-observium-status-hidden-v1';
  var hidden = {};
  var panelOpen = false;
  var scheduled = false;

  try {
    var saved = JSON.parse(localStorage.getItem(KEY) || '{}');
    if (saved && typeof saved === 'object' &&
        !Array.isArray(saved)) {
      hidden = saved;
    }
  } catch (e) {
    hidden = {};
  }

  function save() {
    try {
      localStorage.setItem(KEY, JSON.stringify(hidden));
    } catch (e) {}
  }

  function getDeviceMap(box) {
    var map = box.querySelector('.isp-status-device-map');
    if (!map) return [];

    try {
      var data = JSON.parse(map.getAttribute('data-map') || '[]');
      return Array.isArray(data) ? data : [];
    } catch (e) {
      return [];
    }
  }

  // ISP_STATUS_NAME_IP_LAYOUT_V3
  // Identifica o equipamento sem depender da
  // primeira coluna, que pode indicar severidade.
  function findDevice(row, devices) {
    var links = row.querySelectorAll('a[href]');

    for (var i = 0; i < links.length; i++) {
      var href = links[i].getAttribute('href') || '';

      var match = href.match(
        /(?:device=|\/device\/)(\d+)(?:[\/&#?]|$)/
      );

      if (match) {
        for (var j = 0; j < devices.length; j++) {
          if (String(devices[j].id) === match[1]) {
            return devices[j];
          }
        }
      }
    }

    // Procura o IP em qualquer coluna da linha.
    var cells = row.querySelectorAll('td');

    for (var k = 0; k < devices.length; k++) {
      var ip = String(devices[k].ip || '').trim();
      if (!ip) continue;

      var escaped = ip.replace(
        /[.*+?^${}()|[\]\\]/g,
        '\\$&'
      );

      var pattern = new RegExp(
        '(^|[^0-9a-fA-F:.])' +
        escaped +
        '(?=$|[^0-9a-fA-F:.])'
      );

      for (var n = 0; n < cells.length; n++) {
        if (pattern.test(cells[n].textContent || '')) {
          return devices[k];
        }
      }
    }

    return null;
  }

  // Nome acima do IP, sem invadir a faixa
  // colorida da severidade.
  function addName(row, device) {
    var name = String(device.name || '').trim();
    var ip = String(device.ip || '').trim();

    if (!name || !ip ||
        name.toLowerCase() === ip.toLowerCase()) {
      return;
    }

    var cells = row.querySelectorAll('td');
    var cell = null;

    // Preferir a celula que contem o link do IP.
    for (var i = 0; i < cells.length; i++) {
      var links = cells[i].querySelectorAll('a');

      for (var j = 0; j < links.length; j++) {
        if ((links[j].textContent || '').trim() === ip) {
          cell = cells[i];
          break;
        }
      }

      if (cell) break;
    }

    // Alternativa: celula com o IP em texto.
    if (!cell) {
      for (var k = 0; k < cells.length; k++) {
        if ((cells[k].textContent || '').indexOf(ip) >= 0) {
          cell = cells[k];
          break;
        }
      }
    }

    if (!cell) return;

    var label = cell.querySelector(
      '.isp-status-name-v2'
    );

    if (!label) {
      label = document.createElement('div');
      label.className =
        'isp-status-name-v2 isp-status-name-ip-v3';

      cell.insertBefore(label, cell.firstChild);
    }

    label.textContent = name;
    label.classList.add('isp-status-name-ip-v3');
  }

  function renderList(box, devices) {
    var list = document.querySelector('.isp-status-panel-v2 .isp-status-list-v2');
    if (!list) return;

    var sorted = devices.slice().sort(function (a, b) {
      return String(a.name || a.ip).localeCompare(
        String(b.name || b.ip), 'pt-BR'
      );
    });

    var signature = sorted.map(function (d) {
      return d.id + ':' + d.name + ':' + d.ip;
    }).join('|');

    if (list.getAttribute('data-signature') !== signature) {
      list.textContent = '';
      list.setAttribute('data-signature', signature);

      sorted.forEach(function (device) {
        var label = document.createElement('label');
        label.className = 'isp-status-choice-v2';

        var checkbox = document.createElement('input');
        checkbox.type = 'checkbox';
        checkbox.className = 'isp-status-check-v2';
        checkbox.value = String(device.id);

        var text = document.createElement('span');
        text.textContent =
          String(device.name || device.ip) +
          ' — ' + String(device.ip || '');

        label.appendChild(checkbox);
        label.appendChild(text);
        list.appendChild(label);
      });
    }

    list.querySelectorAll('.isp-status-check-v2').forEach(
      function (checkbox) {
        var desired = !hidden[checkbox.value];

        if (checkbox.checked !== desired) {
          checkbox.checked = desired;
        }
      }
    );
  }

  function refresh() {
    var map = document.querySelector(
      '.grid-stack-item[data-gs-id="4"] .isp-status-device-map'
    );

    // Alternativa para versoes do GridStack que nao
    // mantem data-gs-id no elemento visual.
    if (!map) {
      map = document.querySelector('.isp-status-device-map');
    }

    if (!map) return;

    var box = map.closest('.box');
    if (!box || !box.querySelector('.isp-status-manage-v2')) {
      return;
    }

    var devices = getDeviceMap(box);
    var panel = document.querySelector('.isp-status-panel-v2');
    var button = box.querySelector('.isp-status-manage-v2');

    if (panel && panel.hidden === panelOpen) {
      panel.hidden = !panelOpen;
    }

    if (button) {
      button.setAttribute('aria-expanded', String(panelOpen));
    }

    box.querySelectorAll('.box-content table tr').forEach(
      function (row) {
        if (!row.querySelector('td')) return;

        var device = findDevice(row, devices);
        if (!device) return;

        addName(row, device);

        var desired = hidden[String(device.id)] ? 'none' : '';

        if (row.style.display !== desired) {
          row.style.display = desired;
        }
      }
    );

    renderList(box, devices);
  }

  document.addEventListener('click', function (event) {
    var target = event.target;
    if (!target || !target.closest) return;

    var button = target.closest('.isp-status-manage-v2');

    if (button) {
      panelOpen = !panelOpen;
      refresh();
      return;
    }

    var restore = target.closest('.isp-status-all-v2');

    if (restore) {
      hidden = {};
      save();
      refresh();
    }
  });

  document.addEventListener('change', function (event) {
    var input = event.target;

    if (!input || !input.matches ||
        !input.matches('.isp-status-check-v2')) {
      return;
    }

    if (input.checked) {
      delete hidden[input.value];
    } else {
      hidden[input.value] = true;
    }

    save();
    refresh();
  });

  function schedule() {
    if (scheduled) return;
    scheduled = true;

    setTimeout(function () {
      scheduled = false;
      refresh();
    }, 200);
  }

  function start() {
    refresh();

    var grid = document.querySelector('.grid-stack');

    if (grid) {
      new MutationObserver(schedule).observe(grid, {
        childList: true,
        subtree: true
      });
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }
})();


// ISP_STATUS_MANAGER_STYLE_V2
(function () {
  if (document.getElementById('isp-status-manager-style-v2')) {
    return;
  }

  var style = document.createElement('style');
  style.id = 'isp-status-manager-style-v2';

  style.textContent = `

    .isp-status-header-v2 {
      display: flex !important;
      justify-content: space-between !important;
      align-items: center !important;
      gap: 10px;
    }

    .isp-status-header-v2 .box-title {
      margin: 0 !important;
      text-align: left !important;
    }

    .isp-status-manage-v2 {
      padding: 4px 10px;
      border: 1px solid #bfdbfe;
      border-radius: 5px;
      background: #eff6ff;
      color: #075985;
      font-size: 11px;
      font-weight: 600;
      cursor: pointer;
      flex-shrink: 0;
    }

    .isp-status-manage-v2:hover {
      background: #dbeafe;
    }

    .isp-status-panel-v2 {
      background: #f8fafc;
      padding: 10px 12px;
      border-bottom: 1px solid #e2e8f0;
    }

    .isp-status-panel-v2[hidden] {
      display: none !important;
    }

    .isp-status-panel-info-v2 {
      font-size: 11px;
      color: #64748b;
      margin-bottom: 8px;
    }

    .isp-status-list-v2 {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
      gap: 8px;
      max-height: 160px;
      overflow-y: auto;
    }

    .isp-status-choice-v2 {
      display: flex !important;
      align-items: center;
      gap: 7px;
      font-size: 11px;
      font-weight: 400 !important;
      margin: 0 !important;
      cursor: pointer;
    }

    .isp-status-choice-v2 input {
      margin: 0 !important;
      flex-shrink: 0;
    }

    .isp-status-all-v2 {
      margin-top: 10px;
      padding: 5px 9px;
      border: 1px solid #cbd5e1;
      border-radius: 4px;
      background: #fff;
      font-size: 11px;
      cursor: pointer;
    }

    .isp-status-name-v2 {
      font-size: 11px;
      color: #334155;
      font-weight: 700;
      line-height: 1.5;
    }
  `;

  document.head.appendChild(style);
})();



// ISP_STATUS_NAME_IP_STYLE_V3
(function () {
  if (document.getElementById(
    'isp-status-name-ip-style-v3'
  )) return;

  var style = document.createElement('style');
  style.id = 'isp-status-name-ip-style-v3';

  style.textContent = `

    .isp-status-name-ip-v3 {
      display: block !important;
      font-size: 12px !important;
      line-height: 1.35 !important;
      font-weight: 700 !important;
      color: #334155 !important;
      margin: 0 0 2px 0 !important;
      overflow-wrap: anywhere;
    }

    .grid-stack-item[data-gs-id="4"]
    .box-content table td {
      vertical-align: middle !important;
    }

    .grid-stack-item[data-gs-id="4"]
    .box-content table td a {
      font-size: 11px;
    }

  `;

  document.head.appendChild(style);
})();



// ISP_STATUS_COLUMN_HEADERS_V2
// Cabecalho da tabela de notificacoes.

(function () {
  'use strict';

  if (window.ISPStatusColumnHeadersV2) return;
  window.ISPStatusColumnHeadersV2 = true;

  function updateHeaders() {
    var root = document.querySelector(
      '.grid-stack-item[data-gs-id="4"]'
    );

    if (!root) {
      var map = document.querySelector(
        '.isp-status-device-map'
      );
      if (map) root = map.closest('.box');
    }

    if (!root) return;

    root.querySelectorAll(
      '.box-content table'
    ).forEach(function (table) {

      if (table.querySelector(
        '.isp-status-column-heading-v2'
      )) return;

      var row = Array.from(table.querySelectorAll('tr'))
        .find(function (tr) {
          return tr.querySelectorAll('td').length >= 4;
        });

      if (!row) return;

      var count = row.querySelectorAll('td').length;

      var names;

      if (count === 5) {
        names = [
          '',
          'Equipamento',
          'Ocorrência',
          'Referência',
          'Tempo / Detalhes'
        ];
      } else if (count === 4) {
        names = [
          'Equipamento',
          'Ocorrência',
          'Referência',
          'Tempo / Detalhes'
        ];
      } else {
        return;
      }

      var thead = table.querySelector('thead');

      if (!thead) {
        thead = document.createElement('thead');
        table.insertBefore(thead, table.firstChild);
      }

      var tr = document.createElement('tr');
      tr.className = 'isp-status-column-heading-v2';

      names.forEach(function (name) {
        var th = document.createElement('th');
        th.textContent = name;
        th.scope = 'col';
        tr.appendChild(th);
      });

      thead.appendChild(tr);
    });
  }

  var pending = false;

  function schedule() {
    if (pending) return;
    pending = true;

    setTimeout(function () {
      pending = false;
      updateHeaders();
    }, 180);
  }

  function start() {
    updateHeaders();

    var grid = document.querySelector('.grid-stack');

    if (grid) {
      new MutationObserver(schedule).observe(grid, {
        childList: true,
        subtree: true
      });
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }
})();


// ISP_STATUS_COLUMN_HEADERS_STYLE_V2
(function () {
  if (document.getElementById(
    'isp-status-column-style-v2'
  )) return;

  var style = document.createElement('style');
  style.id = 'isp-status-column-style-v2';

  style.textContent = `

    .isp-status-column-heading-v2 th {
      background: #eef3f8 !important;
      color: #334155 !important;
      font-size: 11px !important;
      font-weight: 700 !important;
      padding: 8px 10px !important;
      border-bottom: 1px solid #d8e1ea !important;
      text-align: left !important;
      white-space: nowrap;
    }

    .grid-stack-item[data-gs-id="4"]
    .isp-status-name-ip-v3 {
      color: #172b4d !important;
      font-weight: 700 !important;
      font-size: 12px !important;
    }

  `;

  document.head.appendChild(style);
})();



// ISP_DASHBOARD_STABILITY_STYLE_V1
// Evita animacoes de deslocamento ao atualizar widgets.
// Nao interfere nos dados, links ou botoes.

(function () {

  if (document.getElementById(
    'isp-dashboard-stability-v1'
  )) {
    return;
  }

  var style = document.createElement('style');
  style.id = 'isp-dashboard-stability-v1';

  style.textContent = `

    .grid-stack > .grid-stack-item {
      transition: none !important;
      animation: none !important;
    }

    .grid-stack > .grid-stack-item >
    .grid-stack-item-content {
      transition:
        top 0s,
        left 0s,
        width 0s,
        height 0s !important;
    }

    .grid-stack .isp-bgp-compact-line,
    .grid-stack .isp-rib-pppoe-box {
      animation: none !important;
    }

  `;

  document.head.appendChild(style);

})();


// ISP_PORT_ALERT_FILTER_V2
// Selecao individual por port_id.
// Preserva os alertas reais do Observium.

(function () {
  'use strict';

  if (window.ISPPortAlertFilterV2) return;
  window.ISPPortAlertFilterV2 = true;

  var KEY = 'isp-observium-hidden-ports-v1';
  var hidden = {};
  var opened = false;
  var search = '';
  // ISP_PORT_ALERT_FILTER_STATES_V3
  var stateFilter = 'down';
  var scheduled = false;

  try {
    var saved = JSON.parse(localStorage.getItem(KEY) || '{}');
    if (saved && typeof saved === 'object' &&
        !Array.isArray(saved)) {
      hidden = saved;
    }
  } catch (e) {
    hidden = {};
  }

  function save() {
    try {
      localStorage.setItem(KEY, JSON.stringify(hidden));
    } catch (e) {}
  }

  function rootBox() {
    var button = document.querySelector(
      '.isp-status-manage-v2'
    );
    return button ? button.closest('.box') : null;
  }

  function inventory(box) {
    var map = box.querySelector('.isp-status-port-map-v2');
    if (!map) return [];

    try {
      var rows = JSON.parse(
        map.getAttribute('data-ports') || '[]'
      );
      return Array.isArray(rows) ? rows : [];
    } catch (e) {
      return [];
    }
  }

  function portState(port) {
    var admin = String(
      port.admin || ''
    ).toLowerCase();

    var oper = String(
      port.oper || ''
    ).toLowerCase();

    if (admin === 'up' &&
        (oper === 'down' ||
         oper === 'lowerlayerdown')) {
      return 'down';
    }

    if (oper === 'up') {
      return 'up';
    }

    return 'other';
  }

  function createUI(box) {
    var header = box.querySelector('.isp-status-header-v2');
    if (!header) return;

    if (!box.querySelector('.isp-port-filter-button-v2')) {
      var button = document.createElement('button');
      button.type = 'button';
      button.className = 'isp-port-filter-button-v2';
      button.textContent = 'Portas';
      header.appendChild(button);
    }

    if (document.querySelector(
      '.isp-port-filter-panel-v2'
    )) {
      return;
    }

    var panel = document.createElement('div');
    panel.className = 'isp-port-filter-panel-v2';
    panel.hidden = true;

    var info = document.createElement('div');
    info.className = 'isp-port-filter-info-v2';
    info.textContent =
      'Marque as portas que deseja exibir nos alertas.';

    var input = document.createElement('input');
    input.type = 'search';
    input.className = 'isp-port-filter-search-v2';
    input.placeholder = 'Buscar equipamento, IP ou porta...';
    input.value = search;

    var stateSelect = document.createElement('select');
    stateSelect.className = 'isp-port-state-select-v3';

    [
      ['down', 'DOWN — portas indisponíveis'],
      ['up', 'UP — portas ativas'],
      ['all', 'Todas as portas'],
      // ISP_PORT_HIDDEN_FILTER_V5
      ['hidden', 'Somente ocultas']
    ].forEach(function (item) {
      var option = document.createElement('option');
      option.value = item[0];
      option.textContent = item[1];
      stateSelect.appendChild(option);
    });

    stateSelect.value = stateFilter;

    var counter = document.createElement('div');
    counter.className = 'isp-port-filter-counter-v2';

    var list = document.createElement('div');
    list.className = 'isp-port-filter-list-v2';

    var restore = document.createElement('button');
    restore.type = 'button';
    restore.className = 'isp-port-filter-restore-v2';
    restore.textContent = 'Mostrar todas as portas';

    panel.appendChild(info);
    panel.appendChild(input);
    panel.appendChild(stateSelect);
    panel.appendChild(counter);
    panel.appendChild(list);
    panel.appendChild(restore);

    // Modal independente do GridStack e do widget.
    var backdrop = document.createElement('div');
    backdrop.className = 'isp-port-modal-backdrop-v4';
    backdrop.hidden = true;

    var modalHeader = document.createElement('div');
    modalHeader.className = 'isp-port-modal-header-v4';

    var modalTitle = document.createElement('strong');
    modalTitle.textContent = 'Gerenciar alertas por porta';

    var close = document.createElement('button');
    close.type = 'button';
    close.className = 'isp-port-modal-close-v4';
    close.textContent = 'Fechar';
    close.setAttribute('aria-label', 'Fechar gerenciador');

    modalHeader.appendChild(modalTitle);
    modalHeader.appendChild(close);

    panel.insertBefore(modalHeader, panel.firstChild);

    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-modal', 'true');
    panel.setAttribute(
      'aria-label', 'Gerenciar alertas por porta'
    );

    document.body.appendChild(backdrop);
    document.body.appendChild(panel);
  }

  function matchPort(row, ports) {
    var cells = row.querySelectorAll('td');

    // Estrutura nativa:
    // 0 severidade, 1 equipamento,
    // 2 ocorrencia, 3 referencia, 4 detalhes.
    if (cells.length !== 5 && cells.length !== 6) return null;

    var occurrence = cells[2].textContent || '';

    if (!/\bport\s+down\b/i.test(occurrence)) {
      return null;
    }

    var refCell = cells[3];
    var links = refCell.querySelectorAll('a[href]');

    // Identificador real da interface quando presente.
    for (var i = 0; i < links.length; i++) {
      var href = links[i].getAttribute('href') || '';

      var match = href.match(
        /(?:port(?:_id)?=|\/port\/)(\d+)(?:[/?&#]|$)/
      );

      if (match) {
        var id = Number(match[1]);

        for (var j = 0; j < ports.length; j++) {
          if (Number(ports[j].id) === id) {
            return ports[j];
          }
        }
      }
    }

    // Alternativa segura: combina dispositivo e interface.
    var deviceText = cells[1].textContent || '';
    var reference = (refCell.textContent || '').trim();

    var possibleDevices = {};

    cells[1].querySelectorAll('a[href]').forEach(
      function (link) {
        var href = link.getAttribute('href') || '';
        var match = href.match(
          /device(?:_id)?=(\d+)/
        );
        if (match) {
          possibleDevices[match[1]] = true;
        }
      }
    );

    ports.forEach(function (port) {
      if (port.ip && deviceText.indexOf(port.ip) >= 0) {
        possibleDevices[String(port.device_id)] = true;
      }
    });

    var candidates = ports.filter(function (port) {
      if (!possibleDevices[String(port.device_id)]) {
        return false;
      }

      var names = [
        port.name,
        port.descr,
        port.alias
      ].map(function (x) {
        return String(x || '').trim().toLowerCase();
      }).filter(Boolean);

      return names.indexOf(reference.toLowerCase()) >= 0;
    });

    // Ambiguidade: nao oculta a porta errada.
    return candidates.length === 1 ? candidates[0] : null;
  }


  // ISP_PORT_MODAL_ACTIONS_V4
  // Adiciona uma coluna exclusiva para acoes.
  function ensureActionsColumns(box) {

    var heading = box.querySelector(
      '.isp-status-column-heading-v2'
    );

    if (heading && !heading.querySelector(
      '.isp-port-actions-heading-v4'
    )) {
      var th = document.createElement('th');
      th.className = 'isp-port-actions-heading-v4';
      th.textContent = 'Ações';
      th.scope = 'col';
      heading.appendChild(th);
    }

    box.querySelectorAll('.box-content table tr')
      .forEach(function (row) {

        var cells = row.querySelectorAll('td');

        if (cells.length !== 5) return;

        var td = document.createElement('td');
        td.className = 'isp-port-actions-cell-v4';

        row.appendChild(td);
      });
  }

  function filterRows(box, ports) {
    ensureActionsColumns(box);
    box.querySelectorAll('.box-content table tr').forEach(
      function (row) {
        if (!row.querySelector('td')) return;

        var port = matchPort(row, ports);
        var key = port ? 'port:' + port.id : '';

        row.classList.toggle(
          'isp-port-hidden-v2',
          Boolean(key && hidden[key])
        );

        // ISP_PORT_HIDE_IN_ROW_V1
        // Somente ocorrencias identificadas como Port Down.
        // Nunca oculta uma interface sem correspondencia unica.
        if (port) {
          var details = row.querySelectorAll('td');

          if (details.length === 6) {
            var cell = details[5];

            var button = row.querySelector(
              '.isp-port-hide-row-v1'
            );

            if (!button) {
              button = document.createElement('button');
              button.type = 'button';
              button.className = 'isp-port-hide-row-v1';
              button.textContent = 'Ocultar';
              button.title =
                'Ocultar avisos desta porta no dashboard';
            }

            button.setAttribute(
              'data-port-id',
              String(port.id)
            );

            // Move o botao existente, sem duplicar.
            if (button.parentNode !== cell) {
              cell.appendChild(button);
            }
          }
        }
      }
    );
  }

  function renderList(box, ports) {
    var panel = document.querySelector(
      '.isp-port-filter-panel-v2'
    );
    if (!panel) return;

    var backdrop = document.querySelector(
      '.isp-port-modal-backdrop-v4'
    );

    if (backdrop) {
      backdrop.hidden = !opened;
    }

    panel.hidden = !opened;

    if (!opened) return;

    var list = panel.querySelector('.isp-port-filter-list-v2');
    var counter = panel.querySelector('.isp-port-filter-counter-v2');

    if (!list || !counter) return;

    var query = search.trim().toLocaleLowerCase('pt-BR');

    var sorted = ports.filter(function (port) {
      var haystack = [
        port.device,
        port.ip,
        port.name,
        port.descr,
        port.alias
      ].join(' ').toLocaleLowerCase('pt-BR');

      var matchesSearch =
        !query || haystack.indexOf(query) >= 0;

      // V5: utiliza as preferencias existentes.
      // Nao altera ou apaga portas ocultadas.
      var matchesState;

      if (stateFilter === 'hidden') {
        matchesState = Boolean(
          hidden['port:' + String(port.id)]
        );
      } else if (stateFilter === 'all') {
        matchesState = true;
      } else {
        matchesState =
          portState(port) === stateFilter;
      }

      return matchesSearch && matchesState;
    });

    sorted.sort(function (a, b) {
      var aa = String(a.device) + ' ' + String(a.name);
      var bb = String(b.device) + ' ' + String(b.name);
      return aa.localeCompare(bb, 'pt-BR', {
        numeric: true
      });
    });

    var visible = sorted.slice(0, 150);

    if (stateFilter === 'hidden') {
      counter.textContent =
        sorted.length === 0
          ? 'Nenhuma porta oculta encontrada.'
          : sorted.length +
            ' porta(s) oculta(s) encontrada(s).';
    } else {
      counter.textContent =
        sorted.length + ' porta(s) encontrada(s)' +
        (sorted.length > 150
          ? ' — exibindo 150. Refine a busca.'
          : '');
    }

    var signature = visible.map(function (port) {
      return [
        port.id,
        port.device,
        port.ip,
        port.name,
        port.alias
      ].join(':');
    }).join('|');

    if (list.getAttribute('data-signature') !== signature) {
      list.textContent = '';
      list.setAttribute('data-signature', signature);

      visible.forEach(function (port) {
        var label = document.createElement('label');
        label.className = 'isp-port-filter-choice-v2';

        var checkbox = document.createElement('input');
        checkbox.type = 'checkbox';
        checkbox.className = 'isp-port-filter-check-v2';
        checkbox.value = 'port:' + port.id;

        var text = document.createElement('span');

        var description = port.alias
          ? ' — ' + port.alias
          : '';

        text.textContent =
          port.device + ' (' + port.ip + ')' +
          ' — ' + port.name + description;

        label.appendChild(checkbox);
        label.appendChild(text);
        list.appendChild(label);
      });
    }

    list.querySelectorAll('.isp-port-filter-check-v2')
      .forEach(function (input) {
        var checked = !hidden[input.value];
        if (input.checked !== checked) {
          input.checked = checked;
        }
      });
  }

  function refresh() {
    var box = rootBox();
    if (!box) return;

    createUI(box);

    var ports = inventory(box);
    filterRows(box, ports);
    renderList(box, ports);

    var button = box.querySelector(
      '.isp-port-filter-button-v2'
    );

    if (button) {
      var hiddenCount = Object.keys(hidden).filter(
        function (key) {
          return Boolean(hidden[key]);
        }
      ).length;

      var label = hiddenCount > 0
        ? 'Portas · ' + hiddenCount + ' ocultas'
        : 'Portas';

      if (button.textContent !== label) {
        button.textContent = label;
      }

      button.setAttribute(
        'aria-expanded', String(opened)
      );
    }
  }

  document.addEventListener('click', function (event) {
    if (!event.target || !event.target.closest) return;

    // Ocultar diretamente na linha do alerta.
    var hideButton = event.target.closest(
      '.isp-port-hide-row-v1'
    );

    if (hideButton) {
      event.preventDefault();
      event.stopPropagation();

      var portId = hideButton.getAttribute('data-port-id');

      if (!portId || !/^\d+$/.test(portId)) return;

      var key = 'port:' + portId;

      hidden[key] = true;
      save();
      refresh();
      return;
    }

    if (
      event.target.closest('.isp-port-modal-close-v4') ||
      event.target.closest('.isp-port-modal-backdrop-v4')
    ) {
      opened = false;
      refresh();
      return;
    }

    if (event.target.closest('.isp-port-filter-button-v2')) {
      opened = !opened;
      refresh();
      return;
    }

    if (event.target.closest('.isp-port-filter-restore-v2')) {
      hidden = {};
      save();
      refresh();
    }
  });

  document.addEventListener('input', function (event) {
    if (!event.target.matches ||
        !event.target.matches('.isp-port-filter-search-v2')) {
      return;
    }

    search = event.target.value;
    refresh();
  });

  document.addEventListener('change', function (event) {
    var input = event.target;

    if (!input || !input.matches) return;

    if (input.matches('.isp-port-state-select-v3')) {
      stateFilter = input.value;
      refresh();
      return;
    }

    if (!input.matches('.isp-port-filter-check-v2')) {
      return;
    }

    if (input.checked) {
      delete hidden[input.value];
    } else {
      hidden[input.value] = true;
    }

    save();
    refresh();
  });

  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape' && opened) {
      opened = false;
      refresh();
    }
  });

  function schedule() {
    if (scheduled) return;
    scheduled = true;

    setTimeout(function () {
      scheduled = false;
      refresh();
    }, 250);
  }

  function start() {
    refresh();

    var grid = document.querySelector('.grid-stack');

    if (grid) {
      new MutationObserver(schedule).observe(grid, {
        childList: true,
        subtree: true
      });
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }
})();


// ISP_PORT_ALERT_FILTER_STYLE_V2
(function () {
  if (document.getElementById('isp-port-filter-style-v2')) {
    return;
  }

  var style = document.createElement('style');
  style.id = 'isp-port-filter-style-v2';

  style.textContent = `

    .isp-port-hidden-v2 {
      display: none !important;
    }

    .isp-status-header-v2 {
      display: flex !important;
      align-items: center !important;
      gap: 10px;
    }

    .isp-port-filter-button-v2 {
      background: #fff;
      color: #075985;
      border: 1px solid #cbd5e1;
      border-radius: 5px;
      padding: 4px 10px;
      font-size: 11px;
      font-weight: 600;
      cursor: pointer;
    }

    .isp-port-filter-panel-v2 {
      padding: 10px 12px;
      background: #f8fafc;
      border-bottom: 1px solid #e2e8f0;
    }

    .isp-port-filter-panel-v2[hidden] {
      display: none !important;
    }

    .isp-port-filter-info-v2,
    .isp-port-filter-counter-v2 {
      color: #64748b;
      font-size: 11px;
      margin-bottom: 8px;
    }

    .isp-port-filter-search-v2 {
      width: 100%;
      max-width: 420px;
      padding: 7px 10px;
      border: 1px solid #cbd5e1;
      border-radius: 5px;
      font-size: 12px;
      margin-bottom: 8px;
    }

    .isp-port-filter-list-v2 {
      display: grid;
      grid-template-columns:
        repeat(auto-fit, minmax(260px, 1fr));
      max-height: 210px;
      overflow-y: auto;
      gap: 8px;
    }

    .isp-port-filter-choice-v2 {
      display: flex !important;
      align-items: center;
      gap: 7px;
      margin: 0 !important;
      font-size: 11px;
      font-weight: 400 !important;
      cursor: pointer;
    }

    .isp-port-filter-choice-v2 input {
      margin: 0 !important;
      flex-shrink: 0;
    }

    .isp-port-filter-restore-v2 {
      margin-top: 10px;
      padding: 5px 10px;
      border-radius: 4px;
      border: 1px solid #cbd5e1;
      background: #fff;
      font-size: 11px;
      cursor: pointer;
    }
  `;

  document.head.appendChild(style);
})();



// ISP_PORT_ALERT_FILTER_STATES_STYLE_V3
(function () {
  if (document.getElementById(
    'isp-port-states-style-v3'
  )) return;

  var style = document.createElement('style');
  style.id = 'isp-port-states-style-v3';

  style.textContent = `

    .isp-port-state-select-v3 {
      display: block;
      width: 100%;
      max-width: 420px;
      padding: 7px 10px;
      margin-bottom: 9px;
      border: 1px solid #cbd5e1;
      border-radius: 5px;
      background: #ffffff;
      color: #334155;
      font-size: 12px;
    }

    .isp-port-filter-button-v2 {
      white-space: nowrap;
    }

  `;

  document.head.appendChild(style);
})();



// ISP_PORT_HIDE_IN_ROW_STYLE_V1
(function () {
  if (document.getElementById(
    'isp-port-hide-row-style-v1'
  )) return;

  var style = document.createElement('style');
  style.id = 'isp-port-hide-row-style-v1';

  style.textContent = `

    .isp-port-hide-row-v1 {
      margin-left: 12px;
      padding: 3px 8px;
      border: 1px solid #cbd5e1;
      border-radius: 4px;
      background: #ffffff;
      color: #475569;
      font-size: 11px;
      font-weight: 500;
      cursor: pointer;
      white-space: nowrap;
    }

    .isp-port-hide-row-v1:hover {
      background: #f1f5f9;
      color: #0f172a;
      border-color: #94a3b8;
    }

    .isp-port-hide-row-v1:focus-visible {
      outline: 2px solid #2563eb;
      outline-offset: 2px;
    }

  `;

  document.head.appendChild(style);
})();



// ISP_PORT_MODAL_ACTIONS_STYLE_V4
(function () {
  if (document.getElementById(
    'isp-port-modal-actions-style-v4'
  )) return;

  var style = document.createElement('style');
  style.id = 'isp-port-modal-actions-style-v4';

  style.textContent = `

    /* Coluna exclusiva para acoes */

    .isp-port-actions-heading-v4,
    .isp-port-actions-cell-v4 {
      width: 100px !important;
      min-width: 100px !important;
      text-align: center !important;
      vertical-align: middle !important;
      white-space: nowrap;
    }

    .isp-port-actions-cell-v4 {
      padding: 5px 8px !important;
    }

    .isp-port-actions-cell-v4
    .isp-port-hide-row-v1 {
      margin: 0 !important;
      padding: 4px 10px !important;
      border: 1px solid #cbd5e1;
      border-radius: 5px;
      background: #fff;
      color: #475569;
      font-size: 11px;
      cursor: pointer;
    }

    .isp-port-actions-cell-v4
    .isp-port-hide-row-v1:hover {
      background: #f1f5f9;
      border-color: #94a3b8;
    }

    /* Fundo escuro do modal */

    .isp-port-modal-backdrop-v4 {
      position: fixed !important;
      inset: 0 !important;
      background: rgba(15,23,42,.55) !important;
      z-index: 10500 !important;
    }

    .isp-port-modal-backdrop-v4[hidden] {
      display: none !important;
    }

    /* Janela independente do GridStack */

    body > .isp-port-filter-panel-v2 {
      position: fixed !important;
      top: 50% !important;
      left: 50% !important;
      transform: translate(-50%, -50%) !important;
      z-index: 10501 !important;

      width: min(1050px, 94vw) !important;
      max-width: 1050px !important;
      max-height: 86vh !important;
      overflow-y: auto !important;

      padding: 22px !important;
      background: #ffffff !important;
      border: 1px solid #cbd5e1 !important;
      border-radius: 10px !important;
      box-shadow:
        0 20px 70px rgba(15,23,42,.25) !important;
    }

    body > .isp-port-filter-panel-v2[hidden] {
      display: none !important;
    }

    .isp-port-modal-header-v4 {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 16px;
      padding-bottom: 14px;
      margin-bottom: 15px;
      border-bottom: 1px solid #e2e8f0;
      color: #0f172a;
      font-size: 17px;
    }

    .isp-port-modal-close-v4 {
      padding: 6px 12px;
      background: #f8fafc;
      border: 1px solid #cbd5e1;
      border-radius: 5px;
      color: #334155;
      cursor: pointer;
      font-size: 12px;
    }

    /* Busca e filtros mais largos */

    body > .isp-port-filter-panel-v2
    .isp-port-filter-search-v2,
    body > .isp-port-filter-panel-v2
    .isp-port-state-select-v3 {
      max-width: 100% !important;
      width: 100% !important;
    }

    /* Lista de interfaces com rolagem ampla */

    body > .isp-port-filter-panel-v2
    .isp-port-filter-list-v2 {
      display: grid !important;
      grid-template-columns:
        repeat(auto-fit, minmax(240px, 1fr)) !important;

      max-height: 46vh !important;
      min-height: 120px;
      overflow-y: auto !important;
      gap: 10px 16px !important;

      padding: 12px !important;
      margin-top: 8px;
      background: #f8fafc;
      border: 1px solid #e2e8f0;
      border-radius: 6px;
    }

    body > .isp-port-filter-panel-v2
    .isp-port-filter-choice-v2 {
      line-height: 1.5 !important;
      font-size: 12px !important;
      align-items: flex-start !important;
      overflow-wrap: anywhere;
    }

    body > .isp-port-filter-panel-v2
    .isp-port-filter-choice-v2 input {
      margin-top: 3px !important;
    }

    @media (max-width: 600px) {
      body > .isp-port-filter-panel-v2 {
        width: 96vw !important;
        padding: 14px !important;
      }

      body > .isp-port-filter-panel-v2
      .isp-port-filter-list-v2 {
        grid-template-columns: 1fr !important;
      }
    }
  `;

  document.head.appendChild(style);
})();



// ISP_DEVICE_MODAL_V3
// Transfere o painel de equipamentos para document.body.
// Nao modifica as preferencias e nem os alertas reais.

(function () {
  'use strict';

  if (window.ISPDeviceModalV3) return;
  window.ISPDeviceModalV3 = true;

  var currentPanel = null;
  var scheduled = false;

  function getButton() {
    return document.querySelector(
      '.isp-status-manage-v2'
    );
  }

  function getPanel() {
    return document.querySelector(
      'body > .isp-status-panel-v2'
    );
  }

  function syncModal() {
    var button = getButton();
    if (!button) return;

    // O AJAX pode criar um novo painel no widget.
    var inlinePanel = document.querySelector(
      '.grid-stack .isp-status-panel-v2'
    );

    if (inlinePanel) {
      var oldPanel = getPanel();

      if (oldPanel && oldPanel !== inlinePanel) {
        oldPanel.remove();
      }

      document.body.appendChild(inlinePanel);
    }

    var panel = getPanel();
    if (!panel) return;

    panel.id = 'isp-status-device-modal-v3';
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-modal', 'true');
    panel.setAttribute(
      'aria-label', 'Gerenciar equipamentos'
    );

    button.setAttribute(
      'aria-controls', panel.id
    );

    if (!panel.querySelector(
      '.isp-device-modal-header-v3'
    )) {
      var header = document.createElement('div');
      header.className = 'isp-device-modal-header-v3';

      var title = document.createElement('strong');
      title.textContent = 'Gerenciar equipamentos';

      var close = document.createElement('button');
      close.type = 'button';
      close.className = 'isp-device-modal-close-v3';
      close.textContent = 'Fechar';
      close.setAttribute(
        'aria-label', 'Fechar gerenciador'
      );

      header.appendChild(title);
      header.appendChild(close);

      panel.insertBefore(header, panel.firstChild);
    }

    var backdrop = document.querySelector(
      '.isp-device-modal-backdrop-v3'
    );

    if (!backdrop) {
      backdrop = document.createElement('div');
      backdrop.className =
        'isp-device-modal-backdrop-v3';
      backdrop.hidden = true;

      document.body.appendChild(backdrop);
    }

    // O gerenciador V2 continua controlando a abertura.
    backdrop.hidden = panel.hidden;

    currentPanel = panel;
  }

  function closeModal() {
    var button = getButton();

    if (button && currentPanel &&
        !currentPanel.hidden) {
      // Utiliza a logica original do gerenciador V2,
      // mantendo o estado interno sincronizado.
      button.click();
      syncModal();
    }
  }

  document.addEventListener('click', function (event) {
    var target = event.target;

    if (!target || !target.closest) return;

    if (target.closest('.isp-device-modal-close-v3') ||
        target.closest('.isp-device-modal-backdrop-v3')) {
      event.preventDefault();
      closeModal();
      return;
    }

    if (target.closest('.isp-status-manage-v2')) {
      // O listener V2 ja alternou o estado do painel.
      syncModal();
    }
  });

  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') {
      closeModal();
    }
  });

  function schedule() {
    if (scheduled) return;
    scheduled = true;

    window.setTimeout(function () {
      scheduled = false;
      syncModal();
    }, 120);
  }

  function start() {
    syncModal();

    var grid = document.querySelector('.grid-stack');

    if (grid) {
      new MutationObserver(schedule).observe(grid, {
        childList: true,
        subtree: true
      });
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener(
      'DOMContentLoaded', start
    );
  } else {
    start();
  }
})();


// ISP_DEVICE_MODAL_STYLE_V3
(function () {
  if (document.getElementById(
    'isp-device-modal-style-v3'
  )) return;

  var style = document.createElement('style');
  style.id = 'isp-device-modal-style-v3';

  style.textContent = `

    .isp-device-modal-backdrop-v3 {
      position: fixed !important;
      inset: 0 !important;
      background: rgba(15,23,42,.55) !important;
      z-index: 10510 !important;
    }

    .isp-device-modal-backdrop-v3[hidden] {
      display: none !important;
    }

    body > .isp-status-panel-v2 {
      position: fixed !important;
      top: 50% !important;
      left: 50% !important;
      transform: translate(-50%, -50%) !important;

      width: min(920px, 94vw) !important;
      max-height: 85vh !important;
      overflow-y: auto !important;

      z-index: 10511 !important;
      box-sizing: border-box !important;

      padding: 22px !important;
      background: #ffffff !important;
      border: 1px solid #cbd5e1 !important;
      border-radius: 10px !important;
      box-shadow:
        0 20px 70px rgba(15,23,42,.25) !important;
    }

    body > .isp-status-panel-v2[hidden] {
      display: none !important;
    }

    .isp-device-modal-header-v3 {
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 14px;

      padding-bottom: 14px;
      margin-bottom: 15px;
      border-bottom: 1px solid #e2e8f0;

      color: #0f172a;
      font-size: 17px;
    }

    .isp-device-modal-close-v3 {
      padding: 6px 12px;
      border: 1px solid #cbd5e1;
      border-radius: 5px;
      background: #f8fafc;
      color: #334155;
      font-size: 12px;
      cursor: pointer;
    }

    body > .isp-status-panel-v2
    .isp-status-list-v2 {
      display: grid !important;
      grid-template-columns:
        repeat(auto-fit, minmax(230px, 1fr)) !important;

      max-height: 48vh !important;
      overflow-y: auto !important;

      gap: 12px 18px !important;
      padding: 14px !important;

      background: #f8fafc;
      border: 1px solid #e2e8f0;
      border-radius: 6px;
    }

    body > .isp-status-panel-v2
    .isp-status-choice-v2 {
      font-size: 12px !important;
      line-height: 1.5 !important;
      overflow-wrap: anywhere;
    }

    @media (max-width: 600px) {
      body > .isp-status-panel-v2 {
        width: 96vw !important;
        padding: 14px !important;
      }

      body > .isp-status-panel-v2
      .isp-status-list-v2 {
        grid-template-columns: 1fr !important;
      }
    }

  `;

  document.head.appendChild(style);
})();
