(function (Drupal, once) {
  Drupal.behaviors.blastHitFeatureViewer = {
    attach(context, settings) {
      const elements = once('blast-fv-init', 'div[id^="blast-hit-container"]', context);
      elements.forEach((el) => {
        if (!el || !el.id) {
          return;
        }
        // This is the array of items passed from the tripal_analysis_blast formatter.
        const hit_data = settings.tripal_analysis_blast.hit_data;
  console.log("CP07 hit_data", hit_data);
        const query_data = settings.tripal_analysis_blast.query_data;
  console.log("CP08 query_data", query_data);
        // Initialize the feature viewer.
        var options = {
          showAxis: true,
          showSequence: true,
          brushActive: true,
          toolbar:true,
          bubbleHelp: true,
          zoomMax:20
        };
        var ft = new FeatureViewer.createFeature(
          query_data.residues,
          "#" + el.id,
          options
        );
      });
    },
  };
})(Drupal, once);

