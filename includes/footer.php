<?php if (isLoggedIn()): ?>
    </div> <!-- Close main-content -->
<?php endif; ?>

<!-- jQuery -->
<script src="/kings-sacco/assets/js/jquery-3.6.0.min.js"></script>



<!-- Bootstrap 5 JS -->
<script src="/kings-sacco/assets/bootstrap/js/bootstrap.bundle.min.js"></script>

<!-- Fontawesome -->
<script src="/kings-sacco/assets/fontawesome/js/fontawesome.js" crossorigin="anonymous"></script>

<!-- DataTables JS -->
<script src="/kings-sacco/assets/datatables/js/jquery.dataTables.min.js"></script>
<script src="/kings-sacco/assets/datatables/js/dataTables.bootstrap5.min.js"></script>
<script src="/kings-sacco/assets/datatables/js/dataTables.buttons.min.js"></script>
<script src="/kings-sacco/assets/datatables/js/buttons.bootstrap5.min.js"></script>
<script src="/kings-sacco/assets/datatables/js/buttons.html5.min.js"></script>
<script src="/kings-sacco/assets/datatables/js/buttons.print.min.js"></script>

<!-- Select2 JS -->
<script src="/kings-sacco/assets/select2/js/select2.min.js"></script>

<!-- SweetAlert2 JS -->
<script src=" /kings-sacco/assets/sweetalert2/js/sweetalert2.all.min.js"></script>

<!-- Chart.js -->
<script src="/kings-sacco/assets/js/chart.js"></script>



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
    document.querySelectorAll(".menu-toggle").forEach(function(menu) {

        menu.addEventListener("click", function() {

            let parent = this.parentElement;

            document.querySelectorAll(".menu-group").forEach(function(group) {

                if (group !== parent) {
                    group.classList.remove("active");
                }

            });

            parent.classList.toggle("active");

        });

    });
</script>
</body>

</html>