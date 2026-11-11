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
        // This is the data passed from the tripal_analysis_blast formatter.
        const query_data = settings.tripal_analysis_blast.query_data;
        const hit_data = settings.tripal_analysis_blast.hit_data;
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
            data: JSON.parse(hit.hsp_pos),
            name: hit.clean_name,
            className: "blast_match", // Can be used for styling.
            color: "#0F8292",
            type: "rect"
          });
        };
      });
    },
  };
})(Drupal, once);
