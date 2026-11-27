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
            <button type = "button" class = "blast-close"> </button>
            ${button.dataset.popupMessage || ''}
          `;

          document.body.appendChild(dialog);

          // Close in the [x] area is clicked.
          dialog.querySelector('.blast-close')
            .addEventListener('click', () => dialog.close());

          // Close if anywhere outside the dialog is clicked.
          dialog.addEventListener('click', (event) => {
            const rect = dialog.getBoundingClientRect();

            const clickedInDialog =
              event.clientY >= rect.top &&
              event.clientY <= rect.bottom &&
              event.clientX >= rect.left &&
              event.clientX <= rect.right;

             if (!clickedInDialog) {
               dialog.close();
             }
           });

          dialog.addEventListener('close', () => dialog.remove());

          dialog.showModal();
        });
      });
    }
  };
})(Drupal, once);
