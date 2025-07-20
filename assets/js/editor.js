jQuery(function($) {
  function insertBackgroundSelector() {
    if (!Array.isArray(fd_ajax.backgrounds) || !fd_ajax.backgrounds.length) return;
    var $wrapper = $('#config-wrapper .uploaded-imgs-wrapper');
    if (!$wrapper.length) return;

    var $block = $('<div class="fd-backgrounds-block"><h4>Backgrounds</h4><div class="fd-backgrounds-list"></div></div>');
    fd_ajax.backgrounds.forEach(function(url) {
      var $thumb = $('<div class="fd-background-thumb"></div>').css('background-image', 'url(' + url + ')');
      $thumb.on('click', function() {
        var loc = new URL(window.location.href);
        loc.searchParams.set('image', url);
        window.location.href = loc.toString();
      });
      $block.find('.fd-backgrounds-list').append($thumb);
    });
    $wrapper.after($block);
  }

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

  insertBackgroundSelector();
});
