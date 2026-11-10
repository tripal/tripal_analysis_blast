(function (Drupal, once) {
  Drupal.behaviors.tripalBlastPopup = {
    attach(context) {
      const triggers = once(
        'tripal-blast-popup',
        '.tripal-analysis-blast-popup-trigger',
        context
      );

      triggers.forEach((button) => {
        button.addEventListener('click', () => {
          const message = button.getAttribute('data-popup-message') || '';

          const dialogContent = document.createElement('div');
          dialogContent.innerHTML = message;

          const modal = Drupal.dialog(dialogContent, {
            'title': Drupal.t('Blast Details'),
            'width': 'fit-content',
            'min-width': '600px',
            'max-width': '98vw',
            classes: {
              'ui-dialog': 'blast-hit-popup',
              'pre': 'blast-align',
            },
          });

          modal.showModal();
        });
      });
    },
  };
})(Drupal, once);
