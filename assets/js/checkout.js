/**
 * ECF DGII Checkout Fields
 *
 * Handles dynamic visibility of RNC-dependent fields and 250k validation.
 */
(function ($) {
    'use strict';

    var $rncField = $('#ecf_rnc_comprador');
    var $rncDependentFields = $('.ecf-rnc-dependent');
    var $rncLabel = $('label[for="ecf_rnc_comprador"]');

    function toggleRncDependentFields() {
        var rncValue = $rncField.val().trim();

        if (rncValue.length > 0) {
            $rncDependentFields.slideDown(200);
            // Make razón social required when RNC is filled
            $('#ecf_razon_social').closest('.form-row').addClass('validate-required');
        } else {
            $rncDependentFields.slideUp(200);
            $('#ecf_razon_social').closest('.form-row').removeClass('validate-required');
        }
    }

    function updateRncRequirement() {
        // Check cart total from the order review table
        var totalText = $('.order-total .woocommerce-Price-amount').last().text();
        var total = parseFloat(totalText.replace(/[^0-9.]/g, '')) || 0;

        if (total >= ecfDgiiCheckout.maxWithoutRnc) {
            $rncLabel.text(ecfDgiiCheckout.requiredMessage);
            $rncField.closest('.form-row').addClass('validate-required');
            $rncField.attr('placeholder', '');
        } else {
            $rncLabel.text(ecfDgiiCheckout.optionalMessage);
            $rncField.closest('.form-row').removeClass('validate-required');
        }
    }

    $(document).ready(function () {
        // Hide dependent fields initially
        $rncDependentFields.hide();

        // Toggle on RNC input
        $rncField.on('input change', toggleRncDependentFields);

        // Only allow digits in RNC field
        $rncField.on('input', function () {
            this.value = this.value.replace(/[^0-9]/g, '');
        });

        // Check initial state
        toggleRncDependentFields();
        updateRncRequirement();

        // Re-check when order totals update (e.g., coupon applied)
        $(document.body).on('updated_checkout', function () {
            updateRncRequirement();
        });
    });
})(jQuery);
