<script>
    document.addEventListener('DOMContentLoaded', function() {
        focusBarcodeInput();
        setupPaidTabToCreate();
    });

    document.addEventListener('livewire:navigated', function() {
        focusBarcodeInput();
        setupPaidTabToCreate();
    });

    function focusBarcodeInput() {
        setTimeout(function() {
            const input = document.querySelector('input[id$="scan_barcode"]');
            if (input) {
                input.focus();
            }
        }, 100);
    }

    function setupPaidTabToCreate() {
        setTimeout(function() {
            const paidInput = document.querySelector('input[id$="paid"]');
            if (paidInput) {
                paidInput.addEventListener('keydown', function(e) {
                    if (e.key === 'Tab' && !e.shiftKey) {
                        e.preventDefault();
                        const createButton = document.querySelector('button[type="submit"]');
                        if (createButton) {
                            createButton.focus();
                        }
                    }
                });
            }
        }, 100);
    }
</script>
