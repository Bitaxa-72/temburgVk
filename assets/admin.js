(function () {
  var buttons = document.querySelectorAll('.termburg-vk-cancel-submit');

  buttons.forEach(function (button) {
    button.addEventListener('click', function (event) {
      if (!window.confirm('Аннулировать промокод?')) {
        event.preventDefault();
      }
    });
  });
})();
