(function ($) {
  Drupal.behaviors.triapl_analysis_blast = {
    attach: function (context, settings) {
    	
      // hide all the HSP description boxes by default
      $(".tripal-analysis-blast-info-hsp-desc").hide();
      
      // when the [more] link is clicked, show the appropriate HSP description box
      $(".tripal-analysis-blast-info-hsp-link").click(function(e) {
        var my_id = e.target.id;
        var re = /hsp-link-(\d+)-(\d+)/;
        var matches = my_id.match(re);
        var analysis_id = matches[1];
        var j = matches[2];
        $(".tripal-analysis-blast-info-hsp-desc").hide();
        $("#hsp-desc-" + analysis_id + "-" +j).show();
      });
      
      // when the [Close] button is clicked on the HSP description close the box
      $(".hsp-desc-close").click(function(e) {
        $(".tripal-analysis-blast-info-hsp-desc").hide();
      });
    }
  };

})(jQuery);