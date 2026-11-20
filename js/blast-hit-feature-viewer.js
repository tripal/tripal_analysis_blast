/**
 * Feature Viewer from https://github.com/calipho-sib/feature-viewer
 */
(function (Drupal, once) {
  Drupal.behaviors.blastHitFeatureViewer = {
    attach(context, settings) {
      const elements = once('blast-fv-init', 'div[id^="blast-hit-container"]', context);
      elements.forEach((el) => {
        if (!el || !el.id) {
          return;
        }

        // This is the data passed from the tripal_analysis_blast formatter,
        // and el.id is based on the drupal field delta value.
        const query_data = settings.tripal_analysis_blast.query_data;
        const hit_data = settings.tripal_analysis_blast.hit_data[el.id];
        // Initialize the feature viewer.
        var options = {
          showAxis: true,
          showSequence: true,
          brushActive: true,
          toolbar: true,
          bubbleHelp: false,
          zoomMax: 3
        };
        var ft = new FeatureViewer.createFeature(
          query_data.residues,
          "#" + el.id,
          options
        );
        for (let i = 0; i < hit_data.length; i++) {
          const hit = hit_data[i];
          ft.addFeature({
            data: hit.hsp_pos,
            name: hit.clean_name,
            // To allow styling if needed.
            className: "blast_match",
            color: hit.color,
            type: "rect"
          });
        };
      });
    },
  };
})(Drupal, once);
