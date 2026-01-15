<script>
    document.addEventListener('DOMContentLoaded', function() {
        focusBarcodeInput();
    });

    document.addEventListener('livewire:navigated', function() {
        focusBarcodeInput();
    });

    function focusBarcodeInput() {
        setTimeout(function() {
            const input = document.querySelector('input[id$="scan_barcode"]');
            if (input) {
                input.focus();
            }
        }, 100);
    }
</script>
