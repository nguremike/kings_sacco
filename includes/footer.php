<?php if (isLoggedIn()): ?>
    </div> <!-- Close main-content -->
<?php endif; ?>

<!-- jQuery -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

<!-- Bootstrap 5 JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>

<!-- DataTables JS -->
<script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.11.5/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.2.2/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.2.2/js/buttons.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.2.2/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.2.2/js/buttons.print.min.js"></script>

<!-- Select2 JS -->
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<!-- SweetAlert2 JS -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@3.7.1/dist/chart.min.js"></script>

<!-- Custom JS -->
<script src="<?php echo APP_URL; ?>/assets/js/main.js"></script>

<script>
    // Initialize DataTables
    $(document).ready(function() {
        $('.datatable').DataTable({
            dom: 'Bfrtip',
            buttons: [
                'copy', 'csv', 'excel', 'pdf', 'print'
            ],
            pageLength: 25,
            responsive: true
        });

        // Initialize Select2
        $('.select2').select2({
            theme: 'bootstrap-5'
        });
    });

    // Global SweetAlert functions
    function showSuccess(message) {
        Swal.fire({
            icon: 'success',
            title: 'Success!',
            text: message,
            timer: 3000,
            showConfirmButton: false
        });
    }

    function showError(message) {
        Swal.fire({
            icon: 'error',
            title: 'Error!',
            text: message
        });
    }

    function confirmAction(message, callback) {
        Swal.fire({
            title: 'Are you sure?',
            text: message,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#3085d6',
            cancelButtonColor: '#d33',
            confirmButtonText: 'Yes, proceed!'
        }).then((result) => {
            if (result.isConfirmed) {
                callback();
            }
        });
    }

    // PDF Export Functions
    function exportToPDF(url) {
        window.open(url, '_blank');
    }

    // Excel Export Functions
    function exportToExcel(url) {
        window.location.href = url;
    }

    // Bulk Export Functions
    function exportBulkPDF(ids, type) {
        let url = 'export-bulk.php?type=' + type + '&ids=' + ids.join(',');
        window.open(url, '_blank');
    }

    function exportBulkExcel(ids, type) {
        let url = 'export-bulk.php?type=' + type + '&format=excel&ids=' + ids.join(',');
        window.location.href = url;
    }

    // Print Function
    function printDocument(url) {
        let printWindow = window.open(url, '_blank');
        printWindow.onload = function() {
            printWindow.print();
        };
    }
</script>
</body>

</html>