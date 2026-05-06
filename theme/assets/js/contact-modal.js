(function () {
    var modal   = document.getElementById('rr-contact-modal');
    var overlay = modal && modal.querySelector('.rr-modal__overlay');
    var closeBtn = modal && modal.querySelector('.rr-modal__close');

    if (!modal) return;

    function openModal() {
        modal.removeAttribute('hidden');
        document.body.classList.add('rr-modal-open');
        if (closeBtn) closeBtn.focus();
    }

    function closeModal() {
        modal.setAttribute('hidden', '');
        document.body.classList.remove('rr-modal-open');
    }

    document.addEventListener('click', function (e) {
        var trigger = e.target.closest('a[href="#contact"]');
        if (trigger) {
            e.preventDefault();
            openModal();
        }
    });

    if (overlay)  overlay.addEventListener('click', closeModal);
    if (closeBtn) closeBtn.addEventListener('click', closeModal);

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !modal.hasAttribute('hidden')) {
            closeModal();
        }
    });
}());
