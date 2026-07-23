function initZenMode() {
    const shell = document.getElementById('appShell');
    const button = document.getElementById('zenModeBtn');
    const storageKey = 'olliverse_zen_mode_active';

    if (!shell || !button) {
        return;
    }

    function setZenMode(active) {
        shell.classList.toggle('zen-mode-active', active);
        document.body.classList.toggle('zen-mode-body', active);
        button.setAttribute('aria-pressed', active ? 'true' : 'false');
        button.setAttribute('aria-label', active ? 'Desativar modo foco' : 'Ativar modo foco');
        button.setAttribute('title', active ? 'Desativar modo foco' : 'Ativar modo foco');
        button.setAttribute('data-tooltip', active ? 'Desativar modo foco' : 'Ativar modo foco');
        button.innerHTML = iconSvg(active ? 'minimize-2' : 'maximize-2');
        sessionStorage.setItem(storageKey, active ? '1' : '0');
    }

    setZenMode(sessionStorage.getItem(storageKey) === '1');

    button.addEventListener('click', function() {
        setZenMode(!shell.classList.contains('zen-mode-active'));
    });

    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape' && shell.classList.contains('zen-mode-active')) {
            setZenMode(false);
        }
    });
}
