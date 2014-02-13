
if (Drupal.jsEnabled) {
   
   $(document).ready(function(){
      // hide the alignment information on the blast results box
      $(".tripal_analysis_blast-info-hsp-desc").hide();

   });
  
   //------------------------------------------------------------
   // Update the blast results based on the user selection
   function tripal_update_blast(link,db_id){
      tripal_startAjax();
      $.ajax({
         url: link.href,
         dataType: 'json',
         type: 'POST',
         success: function(data){         
            $("#blast_db_" + db_id).html(data.update);
            $(".tripal_analysis_blast-info-hsp-desc").hide();
            tripal_stopAjax();
         }
      });
      return false;
   }

   //------------------------------------------------------------
   // Update the blast results based on the user selection
   function tripal_blast_toggle_alignment(analysis_id,hit_id){
      var alignment_box = $("#tripal_analysis_blast-info-hsp-desc-"+analysis_id+"-"+hit_id);
      var toggle_img = $("#tripal_analysis_blast-info-toggle-image-"+analysis_id+"-"+hit_id);
	   var icon_url = toggle_img.attr("src");


      if (alignment_box.is(':visible')) {
         alignment_box.fadeOut('fast');
	      var changed_icon_url = icon_url.replace(/arrow_d.png/,"arrow_r.png");
	      toggle_img.attr("src", changed_icon_url);
	   } else {
         var width = alignment_box.parent().width();
         alignment_box.css("width", width+'px');
         alignment_box.fadeIn('slow');
	      var icon_url = icon_url.replace(/arrow_r.png/,"arrow_d.png");
	      toggle_img.attr("src", icon_url);
	   }
      return false;
   }
   
   // -------------------------------------------------------------
   // Function that toggles the blast droppable subbox content
   function tripal_set_blast_subbox(db_id){
	  
	  $('.blast-hit-arrow-icon').hover(
	     function() {
	        $(this).css("cursor", "pointer");
	     },
	     function() {
	        $(this).css("cursor", "pointer");
	     }
	  );
     $('.blast-hit-arrow-icon').click(function() {
	        
     });
   }
}
