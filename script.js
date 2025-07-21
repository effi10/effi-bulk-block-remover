jQuery(document).ready(function($) {
    $('#effi-analyze').click(function() {
        const postType = $('#effi-post-type').val();
        const blockName = $('#effi-block-name').val();

        $('#effi-results').text('Analyse en cours...');

        $.post(effiAjax.ajax_url, {
            action: 'effi_analyze_blocks',
            post_type: postType,
            block_name: blockName
        }, function(response) {
            if (response.success) {
                $('#effi-results').html(`Bloc trouvé dans <strong>${response.data.found}</strong> publications sur <strong>${response.data.total}</strong>`);
            } else {
                $('#effi-results').text('Erreur : ' + response.data);
            }
        });
    });

    $('#effi-delete').click(function() {
        if (!confirm("Êtes-vous sûr de vouloir supprimer tous ces blocs ?")) return;

        const postType = $('#effi-post-type').val();
        const blockName = $('#effi-block-name').val();

        $('#effi-results').text('Suppression en cours...');

        $.post(effiAjax.ajax_url, {
            action: 'effi_delete_blocks',
            post_type: postType,
            block_name: blockName
        }, function(response) {
            if (response.success) {
                $('#effi-results').text(response.data);
            } else {
                $('#effi-results').text('Erreur : ' + response.data);
            }
        });
    });
});
