// ISP_CATEGORY_VIEW_FIX_V3
(function () {
  'use strict';

  if (window.ISPCategoryViewSwitchV3) return;
  window.ISPCategoryViewSwitchV3 = true;

  var categories = [
    'NAS', 'MGMT', 'LAG', 'TRANSIT',
    'PEERING', 'CGNAT', 'CORE',
    'PPPOE', 'PTP', 'TRUNK', 'DNS', 'HYPERVISOR'
  ];

  var defaults = {
    NAS: 'graphs',
    MGMT: 'ports',
    LAG: 'ports',
    TRANSIT: 'graphs',
    PEERING: 'graphs',
    CGNAT: 'graphs',
    CORE: 'graphs',
    PPPOE: 'graphs',
    PTP: 'graphs',
    TRUNK: 'graphs',
    DNS: 'ports',
    HYPERVISOR: 'ports'
  };

  // Mesma chave da V1: preferencias preservadas.
  var PREFIX = 'isp-observium-category-view-v1:';

  function preference(category) {
    try {
      var value = localStorage.getItem(
        PREFIX + category
      );

      if (value === 'graphs' || value === 'ports') {
        return value;
      }
    } catch (e) {}

    return defaults[category] || 'graphs';
  }

  function save(category, view) {
    try {
      localStorage.setItem(
        PREFIX + category,
        view
      );
    } catch (e) {}
  }

  function getPage() {
    var path = window.location.pathname || '';

    try {
      path = decodeURIComponent(path);
    } catch (e) {}

    var match = path.match(
      /\/iftype\/type=([^/?#]+)/i
    );

    if (match) {
      var type = match[1].toUpperCase();

      if (categories.indexOf(type) !== -1) {
        return {
          category: type,
          view: 'graphs'
        };
      }
    }

    match = path.match(
      /\/ports\/ifAlias=([^/?#]+)/i
    );

    if (match) {
      var alias = match[1].trim();

      if (!alias.endsWith(':')) return null;

      var category = alias.slice(
        0, -1
      ).toUpperCase();

      if (categories.indexOf(category) !== -1) {
        return {
          category: category,
          view: 'ports'
        };
      }
    }

    // Suporta tambem URLs com parametros comuns.
    var params = new URLSearchParams(
      window.location.search
    );

    var page = (
      params.get('page') || ''
    ).toLowerCase();

    if (page === 'iftype') {
      var graphCategory = (
        params.get('type') || ''
      ).toUpperCase();

      if (categories.indexOf(graphCategory) !== -1) {
        return {
          category: graphCategory,
          view: 'graphs'
        };
      }
    }

    if (page === 'ports') {
      var aliasValue = (
        params.get('ifAlias') || ''
      ).trim();

      if (aliasValue.endsWith(':')) {
        var portCategory = aliasValue.slice(
          0, -1
        ).toUpperCase();

        if (categories.indexOf(portCategory) !== -1) {
          return {
            category: portCategory,
            view: 'ports'
          };
        }
      }
    }

    return null;
  }

  function url(category, view) {
    if (view === 'graphs') {
      return '/iftype/type=' +
        encodeURIComponent(category) + '/';
    }

    return '/ports/ifAlias=' +
      encodeURIComponent(category + ':') + '/';
  }

  function button(category, view, selected) {
    var link = document.createElement('a');

    link.href = url(category, view);

    link.className =
      'isp-category-view-option-v1' +
      (selected === view ? ' is-active' : '');

    link.textContent = view === 'graphs'
      ? 'Gráficos'
      : 'Lista de Portas';

    if (selected === view) {
      link.setAttribute('aria-current', 'page');
    }

    link.addEventListener('click', function () {
      save(category, view);
    });

    return link;
  }

  function createToolbar(info) {
    if (document.querySelector(
      '.isp-category-view-toolbar-v1'
    )) return;

    var bar = document.createElement('div');
    bar.className =
      'isp-category-view-toolbar-v1';

    var label = document.createElement('div');
    label.className =
      'isp-category-view-label-v1';

    var title = document.createElement('strong');
    title.textContent = info.category;

    var description = document.createElement('span');
    description.textContent =
      'Visualização da categoria';

    label.appendChild(title);
    label.appendChild(description);

    var controls = document.createElement('div');
    controls.className =
      'isp-category-view-buttons-v1';

    controls.appendChild(
      button(info.category, 'graphs', info.view)
    );

    controls.appendChild(
      button(info.category, 'ports', info.view)
    );

    bar.appendChild(label);
    bar.appendChild(controls);

    // Na pagina de graficos, mantem a posicao
    // acima do primeiro painel.
    var target = null;

    if (info.view === 'graphs') {
      target = document.querySelector(
        '#content .box, ' +
        '.content-wrapper .box, ' +
        '.content .box'
      );
    }

    if (target && target.parentNode) {
      target.parentNode.insertBefore(bar, target);
      return;
    }

    // Na pagina /ports/, nao depende de .box.
    // A barra e inserida apos a navbar principal.
    var header = document.querySelector(
      'header'
    );

    if (header && header.parentNode) {
      bar.classList.add(
        'isp-category-view-standalone-v2'
      );

      header.insertAdjacentElement(
        'afterend', bar
      );

      return;
    }

    // Ultima alternativa: sempre exibir o seletor.
    document.body.insertBefore(
      bar,
      document.body.firstChild
    );
  }

  function initialize() {
    var info = getPage();

    if (!info) return;

    var preferred = preference(info.category);

    if (preferred !== info.view) {
      window.location.replace(
        url(info.category, preferred)
      );
      return;
    }

    createToolbar(info);
  }

  if (document.readyState === 'loading') {
    document.addEventListener(
      'DOMContentLoaded',
      initialize,
      { once: true }
    );
  } else {
    initialize();
  }
})();