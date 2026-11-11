(function (Drupal, once) {
  Drupal.behaviors.tripalBlastPopup = {
    attach(context) {
      once(
        'tripal-blast-popup',
        '.tripal-analysis-blast-popup-trigger',
        context
      ).forEach((button) => {

        button.addEventListener('click', () => {
          const dialog = document.createElement('dialog');

          dialog.classList.add('blast-hit-popup');

          dialog.innerHTML = `
            <button type="button" class="blast-close"></button>
            ${button.dataset.popupMessage || ''}
          `;

          document.body.appendChild(dialog);
          dialog.querySelector('.blast-close')
            .addEventListener('click', () => dialog.close());

          dialog.addEventListener('close', () => dialog.remove());

          dialog.showModal();
        });
      });
    }
  };
})(Drupal, once);
