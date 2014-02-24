
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
    
   // ------------------------------------------------------------
   // Use genbank style parser. Hide regular expression text feilds
   function tripal_set_genbank_style (){
	  // Disable regular expressions if genbank style parser is used (checked)
	  if ($("#edit-gb-style-parser").is(":checked")) {
		  $("#edit-hit-id-wrapper > label").css("color", "grey");
		  $("#edit-hit-def-wrapper > label").css("color", "grey");
		  $("#edit-hit-accession-wrapper > label").css("color", "grey");
		  $("#edit-hit-id").attr('disabled', 'disabled');
		  $("#edit-hit-def").attr('disabled', 'disabled');
		  $("#edit-hit-accession").attr('disabled', 'disabled');
		  $("#edit-hit-organism-re").attr('disabled', 'disabled');
		  $("#edit-hit-organism").attr('disabled', 'disabled');		  
	  } else {
		  $("#edit-hit-id-wrapper > label").css("color", "black");
		  $("#edit-hit-def-wrapper > label").css("color", "black");
		  $("#edit-hit-accession-wrapper > label").css("color", "black");
		  $("#edit-hit-id").removeAttr('disabled');
		  $("#edit-hit-def").removeAttr('disabled');
		  $("#edit-hit-accession").removeAttr('disabled');
		  $("#edit-hit-organism-re").removeAttr('disabled', 'disabled');
		  $("#edit-hit-organism").removeAttr('disabled', 'disabled');
	  }
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
   
   	//------------------------------------------------------------
	// Update the blast best hit report for selected page and sorting
	function tripal_update_best_hit_report(obj, analysis_id, sort, descending, per_page){
		var page = obj.selectedIndex + 1;
		var baseurl = location.href.substring(0,location.href.lastIndexOf('/tripal_blast_report/'));
		var link = baseurl + '/tripal_blast_report/' + analysis_id + "/" + page + "/" + sort + "/" + descending + "/" + per_page;

		tripal_startAjax();
		$.ajax({
			url: link,
			dataType: 'html',
			type: 'POST',
			success: function(data){
				var d = document.createElement('div');
				d.innerHTML = data;
				var divs = d.getElementsByTagName("div");
				for (var i = 0; i < divs.length; i ++) {
					if (divs[i].getAttribute('id') == 'blast-hits-report') {	
						var report_table = document.getElementById('blast-hits-report');
						report_table.innerHTML = divs[i].innerHTML;
						var table_breport = document.getElementById('tripal_blast_report_table');
						var sel = document.getElementById('tripal_blast_report_page_selector');
						sel.options[page - 1].selected = true;
						tripal_stopAjax();
					}
				}
			}
		});
		
		return false;
	}
}
