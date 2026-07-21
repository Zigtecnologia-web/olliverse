function initPluginPanel() {
    const menu = document.getElementById('pluginsMenu');
    const menuButton = document.getElementById('pluginsMenuBtn');

    if (!menu || !menuButton) {
        return;
    }

    window.OlliversePlugins = window.OlliversePlugins || {};
    window.OlliversePlugins.active = new Set((window.OlliverseConfig.activePlugins || []).map((plugin) => plugin.slug));
    window.OlliversePlugins.registry = window.OlliverseConfig.plugins || [];
    window.OlliversePlugins.processMessage = function(messageDiv) {
        Object.entries(window.OlliversePlugins).forEach(([slug, plugin]) => {
            if (!window.OlliversePlugins.active.has(slug)) {
                return;
            }

            if (plugin && typeof plugin.processMessage === 'function') {
                plugin.processMessage(messageDiv);
            }
        });
    };

    menuButton.addEventListener('click', function() {
        const isOpen = menu.classList.toggle('open');

        menuButton.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    });

    document.querySelectorAll('[data-plugin-toggle]').forEach((input) => {
        input.addEventListener('change', function(event) {
            togglePlugin(event.target, event.target.checked);
        });
    });

    document.addEventListener('click', function(event) {
        if (!menu.contains(event.target)) {
            closePluginsMenu();
        }
    });

    loadActivePluginAssets().then(processAllPluginMessages);
}

function closePluginsMenu() {
    const menu = document.getElementById('pluginsMenu');
    const menuButton = document.getElementById('pluginsMenuBtn');

    if (!menu || !menuButton) {
        return;
    }

    menu.classList.remove('open');
    menuButton.setAttribute('aria-expanded', 'false');
}

function togglePlugin(input, active) {
    const status = document.getElementById('pluginsStatus');
    const slug = input.dataset.pluginToggle || '';
    const body = new URLSearchParams({
        plugin: slug,
        active: active ? '1' : '0',
    });

    input.disabled = true;
    setPluginStatus('Salvando...');

    fetch(`${window.location.pathname}?action=plugin_toggle`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: body.toString(),
    })
    .then((response) => response.json().then((payload) => ({ ok: response.ok, payload })))
    .then(({ ok, payload }) => {
        if (!ok || !payload.success) {
            throw new Error(payload.error || 'Não foi possível atualizar o plugin.');
        }

        window.OlliverseConfig.plugins = payload.plugins || [];
        window.OlliverseConfig.activePlugins = payload.active_plugins || [];
        window.OlliversePlugins.registry = window.OlliverseConfig.plugins;
        window.OlliversePlugins.active = new Set(window.OlliverseConfig.activePlugins.map((plugin) => plugin.slug));

        return active ? loadPluginAssets(pluginConfig(slug)) : Promise.resolve();
    })
    .then(() => {
        if (active) {
            processAllPluginMessages();
        }

        setPluginStatus(active ? 'Plugin ativado' : 'Plugin desativado');
    })
    .catch((error) => {
        input.checked = !active;
        setPluginStatus(error.message || 'Não foi possível atualizar o plugin.', true);
    })
    .finally(() => {
        input.disabled = false;

        if (status) {
            window.setTimeout(() => setPluginStatus(''), 2200);
        }
    });
}

function loadActivePluginAssets() {
    return Promise.all((window.OlliverseConfig.activePlugins || []).map(loadPluginAssets));
}

function loadPluginAssets(plugin) {
    if (!plugin) {
        return Promise.resolve();
    }

    const tasks = [];
    const dependencies = plugin.dependencies?.js || [];
    const style = plugin.assets?.style;
    const script = plugin.assets?.script;

    if (style) {
        tasks.push(loadStylesheet(style, plugin.slug));
    }

    dependencies.forEach((src) => {
        tasks.push(loadScript(src, plugin.slug));
    });

    if (script) {
        tasks.push(Promise.all(tasks).then(() => loadScript(script, plugin.slug)));
    }

    return Promise.all(tasks);
}

function loadStylesheet(href, slug) {
    if (document.querySelector(`link[href="${cssEscape(href)}"]`)) {
        return Promise.resolve();
    }

    const link = document.createElement('link');

    link.rel = 'stylesheet';
    link.href = href;
    link.dataset.pluginAsset = slug;
    document.head.appendChild(link);

    return Promise.resolve();
}

function loadScript(src, slug) {
    if (document.querySelector(`script[src="${cssEscape(src)}"]`)) {
        return Promise.resolve();
    }

    return new Promise((resolve, reject) => {
        const script = document.createElement('script');

        script.src = src;
        script.dataset.pluginAsset = slug;
        script.onload = resolve;
        script.onerror = () => reject(new Error('Não foi possível carregar os recursos do plugin.'));
        document.body.appendChild(script);
    });
}

function processAllPluginMessages() {
    document.querySelectorAll('.message.assistant').forEach((messageDiv) => {
        window.OlliversePlugins.processMessage(messageDiv);
    });
}

function pluginConfig(slug) {
    return (window.OlliverseConfig.plugins || []).find((plugin) => plugin.slug === slug);
}

function setPluginStatus(message, error = false) {
    const status = document.getElementById('pluginsStatus');

    if (!status) {
        return;
    }

    status.textContent = message;
    status.classList.toggle('error', error);
}

function cssEscape(value) {
    if (window.CSS && typeof window.CSS.escape === 'function') {
        return window.CSS.escape(value);
    }

    return String(value).replace(/"/g, '\\"');
}
