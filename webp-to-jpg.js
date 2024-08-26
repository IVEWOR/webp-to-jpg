jQuery(document).ready(function ($) {
    $('#webp-conversion-form').submit(function (event) {
        event.preventDefault();
        var $form = $(this);
        var data = $form.serialize();

        $.ajax({
            type: 'POST',
            url: $form.attr('action'),
            data: data,
            success: function (response) {
                if (response.success) {
                    $('#conversion-result').text('Conversion successful: ' + response.data);
                } else {
                    $('#conversion-result').text('Conversion failed: ' + response.data);
                }
            },
            error: function (xhr, status, error) {
                $('#conversion-result').text('An error occurred.');
            }
        });
    });
});
