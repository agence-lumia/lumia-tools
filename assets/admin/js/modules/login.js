/**
 * Login module: admin JS
 * Handles the media pickers (logo + panel image) and the color pickers.
 */
(function () {
  "use strict";

  document.addEventListener("DOMContentLoaded", function () {
    initMediaPickers();
    initColorPickers();
    initColorResets();
  });

  /* ================================================================
   * MEDIA PICKERS
   * ================================================================ */

  function initMediaPickers() {
    document.querySelectorAll(".lumia-media-picker").forEach(function (picker) {
      var hiddenInput = picker.querySelector('input[type="hidden"]');
      var preview = picker.querySelector(".lumia-media-preview");
      var previewImg = preview ? preview.querySelector("img") : null;
      var selectBtn = picker.querySelector(".lumia-media-select");
      var removeBtn = picker.querySelector(".lumia-media-remove");

      if (!hiddenInput || !selectBtn) return;

      var frame;

      selectBtn.addEventListener("click", function () {
        // Reuses the frame if already open
        if (frame) {
          frame.open();
          return;
        }

        frame = wp.media({
          title: selectBtn.dataset.title || "",
          button: { text: selectBtn.dataset.button || "" },
          multiple: false,
          library: { type: "image" },
        });

        frame.on("select", function () {
          var attachment = frame
            .state()
            .get("selection")
            .first()
            .toJSON();

          hiddenInput.value = attachment.id;

          var imgUrl =
            (attachment.sizes && attachment.sizes.medium
              ? attachment.sizes.medium.url
              : null) || attachment.url;

          if (preview) {
            if (!previewImg) {
              previewImg = document.createElement("img");
              previewImg.alt = "";
              preview.appendChild(previewImg);
            }
            previewImg.src = imgUrl;
            preview.classList.add("has-image");
          }

          if (removeBtn) removeBtn.classList.remove("is-hidden");
        });

        frame.open();
      });

      if (removeBtn) {
        removeBtn.addEventListener("click", function () {
          hiddenInput.value = "0";

          if (previewImg) {
            previewImg.src = "";
            previewImg.remove();
            previewImg = null;
          }

          if (preview) preview.classList.remove("has-image");
          removeBtn.classList.add("is-hidden");

          // Resets the frame to force a new selection
          frame = null;
        });
      }
    });
  }

  /* ================================================================
   * COLOR PICKERS: updates the displayed hex value live
   * ================================================================ */

  function initColorPickers() {
    document.querySelectorAll(".lumia-color-field").forEach(function (field) {
      var input = field.querySelector('input[type="color"]');
      var label = field.querySelector(".lumia-color-field__value");
      var resetBtn = field.querySelector(".lumia-color-reset");

      if (!input || !label) return;

      function updateResetVisibility() {
        if (!resetBtn) return;
        var def = resetBtn.dataset.default;
        resetBtn.style.display = def && input.value.toLowerCase() !== def.toLowerCase() ? "" : "none";
      }

      updateResetVisibility();

      input.addEventListener("input", function () {
        label.textContent = input.value;
        updateResetVisibility();
      });
    });
  }

  function initColorResets() {
    document.querySelectorAll(".lumia-color-reset").forEach(function (btn) {
      btn.addEventListener("click", function () {
        var field = btn.closest(".lumia-color-field");
        if (!field) return;
        var input = field.querySelector('input[type="color"]');
        var label = field.querySelector(".lumia-color-field__value");
        var def = btn.dataset.default;
        if (input && def) {
          input.value = def;
          if (label) label.textContent = def;
          btn.style.display = "none";
        }
      });
    });
  }
})();
