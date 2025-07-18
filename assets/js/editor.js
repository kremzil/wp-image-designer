jQuery(function($) {
  $('#open-designer').on('click', function() {
    const popup = window.open(
      fd_ajax.popup_url + '?image=' + encodeURIComponent(fd_ajax.image_url),
      'FilerobotDesigner',
      'width=1200,height=800'
    );

    const listener = function(event) {
      if (event.origin !== location.origin) return;
      if (event.data.type === 'designSaved') {
        $('#fd-design-url').val(event.data.url);
        alert('Dizajn uložený!');
        $('.single_add_to_cart_button').trigger('click');
        window.removeEventListener('message', listener);
      }
    };

    window.addEventListener('message', listener);
  });
});
