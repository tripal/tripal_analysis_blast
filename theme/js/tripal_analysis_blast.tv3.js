(function ($) {
  
  Drupal.behaviors.tripal_analysis_blast = {
    attach: function (context, settings) {

      /**
       * JS to add the feature viewer.
       */
      tripal_blast_init_feature_viewer();
      
      // Remove the jquery.ui override of our link theme:
      $(".ui-widget-content").removeClass('ui-widget-content')

      
      /**
       * JS for hiding/showing the results table.
       */
      
      // Hide all the results tables.
      $(".tripal-analysis-blast-table").hide();
      $(".tripal-analysis-blast-table-link").click(function(e) {
        var my_id = e.target.id;
        var re = /tripal-analysis-blast-table-link-(\d+)/;
        var matches = my_id.match(re);
        var analysis_id = matches[1];

        var table = $("#tripal-analysis-blast-table-" + analysis_id)
        if (table.is(':visible') === true) {
          table.hide();
        }
        else {
          table.show();
          $(this).text('Hide Results Table');
        }
      });
      
      /**
       * JS for the HSP box the appears when the [more] link is clicked.
       */
      
      // Hide all the HSP description boxes by default
      $(".tripal-analysis-blast-info-hsp-desc").hide();

      // When the [more] link is clicked, show the appropriate HSP description box
      $(".tripal-analysis-blast-info-hsp-link").click(function(e) {
        var my_id = e.target.id;
        var re = /hsp-link-(\d+)-(\d+)/;
        var matches = my_id.match(re);
        var analysis_id = matches[1];
        var j = matches[2];
        $(".tripal-analysis-blast-info-hsp-desc").hide();
        $("#hsp-desc-" + analysis_id + "-" +j).show();
      });

      // When the [Close] button is clicked on the HSP description close the box
      $(".hsp-desc-close").click(function(e) {
        $(".tripal-analysis-blast-info-hsp-desc").hide();
      });

      // Add the anchor to the pager links so that when the user clicks a pager
      // link and the page refreshes they are taken back to the location
      // on the page that they were viewing.
      $("div.tripal_analysis_blast-info-box-desc ul.pager a").each(function() {
        pager_link = $(this);
        parent = pager_link.parents('div.tripal_analysis_blast-info-box-desc');
        pager_link.attr('href', pager_link.attr('href') + '#' + parent.attr('id'));
      })
    }
  };
  
  /**
   * Initializes the feature viewers on the page.
   */
  function tripal_blast_init_feature_viewer(){
	  // For each element of class "blast-alignment-viewer" add the
      // feature viewer.
      $(".blast-alignment-viewer").each(function() {
        $(this).css('width', $(this).attr("width"));
        // Create the feature viewer for this analysis.
        var id = $(this).attr('id');
        var analysis_id = $(this).attr('analysis_id');
        var residues = $(this).attr('residues');
        var options = {
          showAxis: true,
          showSequence: true,
          brushActive: true, 
          toolbar: true, 
          bubbleHelp: true,
          zoomMax: 3 
        }
        var fv = new FeatureViewer(residues, '#' + id, options);   
      
        // Add the hits for this analysis. The information for these hits
        // are found in the data table.
        $(".blast-hits-" + analysis_id).each(function() {
          var hit_name = $(this).attr('hit_name');
          var class_name = $(this).attr('fv_class');
          var data = JSON.parse($(this).attr('fv_data'));
        
          if (data) {
            fv.addFeature({
              data: data,
              name: hit_name,
              className: class_name,
              color: "#888888",
              type: "rect"
            });
          }
        })
        
        fv.onFeatureSelected(function (d) {
    	    var id = d.detail.id;
    	    var re = /tripal-analysis-blast-hsp-(\d+)-(\d+)/;
            var matches = id.match(re);
            var analysis_id = matches[1];
            var j = matches[2];
            $(".tripal-analysis-blast-info-hsp-desc").hide();
            $("#hsp-desc-" + analysis_id + "-" +j).show();
    	});
      });
  }
})(jQuery);

