(() => {
    const tooltip = document.createElement('div');
    tooltip.className = 'origin-tooltip';
    tooltip.setAttribute('aria-hidden', 'true');
    tooltip.hidden = true;

    const heading = document.createElement('strong');
    const description = document.createElement('span');
    tooltip.append(heading, description);
    document.body.append(tooltip);

    let active = null;
    let pinned = false;
    let hideTimer;

    function hide() {
        clearTimeout(hideTimer);
        active?.classList.remove('origin-active');
        active = null;
        pinned = false;
        tooltip.hidden = true;
    }

    function show(button) {
        clearTimeout(hideTimer);
        if (active !== button) {
            hide();
        }
        active = button;
        active.classList.add('origin-active');
        heading.textContent = button.dataset.originTitle;
        description.textContent = button.dataset.originDetail;
        tooltip.hidden = false;

        const anchor = button.getBoundingClientRect();
        const { offsetWidth: width, offsetHeight: height } = tooltip;
        const left = Math.min(Math.max(12, anchor.left + (anchor.width - width) / 2), document.documentElement.clientWidth - width - 12);
        const top = anchor.top >= height + 12 ? anchor.top - height - 4 : anchor.bottom + 4;
        tooltip.style.left = `${left}px`;
        tooltip.style.top = `${top}px`;
    }

    function hideSoon() {
        clearTimeout(hideTimer);
        if (!pinned) {
            hideTimer = setTimeout(hide, 150);
        }
    }

    for (const button of document.querySelectorAll('.origin-icon')) {
        button.addEventListener('pointerenter', event => {
            if (event.pointerType !== 'touch' && !pinned) {
                show(button);
            }
        });
        button.addEventListener('pointerleave', hideSoon);
        button.addEventListener('focus', () => show(button));
        button.addEventListener('blur', hideSoon);
        button.addEventListener('click', () => {
            if (active === button && pinned) {
                hide();
            } else {
                show(button);
                pinned = true;
            }
        });
    }

    tooltip.addEventListener('pointerenter', () => clearTimeout(hideTimer));
    tooltip.addEventListener('pointerleave', hideSoon);
    document.addEventListener('pointerdown', event => {
        if (active && !active.contains(event.target) && !tooltip.contains(event.target)) {
            hide();
        }
    });
    document.addEventListener('keydown', event => {
        if (event.key === 'Escape') {
            hide();
        }
    });
    window.addEventListener('scroll', hide, { capture: true, passive: true });
    window.addEventListener('resize', hide);
})();
