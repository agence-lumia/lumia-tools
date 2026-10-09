/**
 * Lümia Tools - Security admin JS
 * Conditional visibility of sub-options + live seconds → minutes conversion.
 */
(function () {
  "use strict";

  document.addEventListener("DOMContentLoaded", function () {
    initSecurityToggles();
    initSecondsToMinutes();
  });

  /* ================================================================
   * CONDITIONAL VISIBILITY
   * [data-security-toggle] toggles → show/hide [data-depends-on]
   * ================================================================ */

  function initSecurityToggles() {
    var toggles = document.querySelectorAll("[data-security-toggle]");

    toggles.forEach(function (toggle) {
      toggle.addEventListener("change", function () {
        var key = this.getAttribute("data-security-toggle");
        var dependents = document.querySelectorAll(
          '[data-depends-on="' + key + '"]',
        );
        dependents.forEach(function (el) {
          el.style.display = toggle.checked ? "" : "none";
        });
      });
    });
  }

  /* ================================================================
   * LIVE SECONDS → MINUTES CONVERSION
   * [data-seconds-field] inputs update [data-minutes-display]
   * ================================================================ */

  function initSecondsToMinutes() {
    var fields = document.querySelectorAll("[data-seconds-field]");
    var i18n = (window.lumiaAdmin && window.lumiaAdmin.i18n) || {};

    function format(template, value) {
      return (template || "").replace("%d", value);
    }

    fields.forEach(function (input) {
      var targetId = input.getAttribute("data-seconds-field");
      var display = document.getElementById(targetId);
      if (!display) return;

      function update() {
        var seconds = parseInt(input.value, 10);
        if (isNaN(seconds) || seconds <= 0) {
          display.textContent = "";
          return;
        }
        if (seconds < 60) {
          display.textContent = format(i18n.secondsFormat, seconds);
        } else {
          var mins = Math.round(seconds / 60);
          display.textContent = format(i18n.minutesFormat, mins);
        }
      }

      input.addEventListener("input", update);
      update();
    });
  }
})();
