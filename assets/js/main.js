// Main JavaScript File

$(document).ready(function () {
  // Initialize tooltips
  var tooltipTriggerList = [].slice.call(
    document.querySelectorAll('[data-bs-toggle="tooltip"]'),
  );
  var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
    return new bootstrap.Tooltip(tooltipTriggerEl);
  });

  // Initialize popovers
  var popoverTriggerList = [].slice.call(
    document.querySelectorAll('[data-bs-toggle="popover"]'),
  );
  var popoverList = popoverTriggerList.map(function (popoverTriggerEl) {
    return new bootstrap.Popover(popoverTriggerEl);
  });

  // Auto-hide alerts after 5 seconds
  setTimeout(function () {
    $(".alert").fadeOut("slow");
  }, 5000);

  // Confirm delete function
  window.confirmDelete = function (message, callback) {
    Swal.fire({
      title: "Are you sure?",
      text: message,
      icon: "warning",
      showCancelButton: true,
      confirmButtonColor: "#3085d6",
      cancelButtonColor: "#d33",
      confirmButtonText: "Yes, delete it!",
    }).then((result) => {
      if (result.isConfirmed) {
        callback();
      }
    });
  };

  // Format currency input
  $(".currency-input").on("input", function () {
    var value = $(this).val().replace(/[^\d]/g, "");
    if (value) {
      $(this).val(parseInt(value).toLocaleString());
    }
  });

  // Phone number validation
  $(".phone-input").on("input", function () {
    var value = $(this)
      .val()
      .replace(/[^\d+]/g, "");
    $(this).val(value);
  });

  // National ID validation
  $(".id-input").on("input", function () {
    var value = $(this).val().replace(/[^\d]/g, "");
    $(this).val(value);
  });
});

// AJAX request helper
function ajaxRequest(url, method, data, successCallback, errorCallback) {
  $.ajax({
    url: url,
    type: method,
    data: JSON.stringify(data),
    contentType: "application/json",
    dataType: "json",
    success: function (response) {
      if (response.success) {
        if (successCallback) successCallback(response);
      } else {
        Swal.fire({
          icon: "error",
          title: "Error",
          text: response.message || "An error occurred",
        });
        if (errorCallback) errorCallback(response);
      }
    },
    error: function (xhr, status, error) {
      Swal.fire({
        icon: "error",
        title: "Error",
        text: "Network error. Please try again.",
      });
      if (errorCallback) errorCallback(error);
    },
  });
}

// Export to Excel
function exportToExcel(data, filename) {
  var wb = XLSX.utils.book_new();
  var ws = XLSX.utils.json_to_sheet(data);
  XLSX.utils.book_append_sheet(wb, ws, "Sheet1");
  XLSX.writeFile(wb, filename + ".xlsx");
}

// Print element
function printElement(elementId) {
  var printContent = document.getElementById(elementId).innerHTML;
  var originalContent = document.body.innerHTML;

  document.body.innerHTML = printContent;
  window.print();
  document.body.innerHTML = originalContent;
  location.reload();
}

// Date range picker
function initDateRangePicker(selector) {
  $(selector).daterangepicker({
    opens: "left",
    locale: {
      format: "YYYY-MM-DD",
    },
  });
}

// Number to words (for receipts)
function numberToWords(num) {
  // Simple implementation - use library in production
  const ones = [
    "",
    "One",
    "Two",
    "Three",
    "Four",
    "Five",
    "Six",
    "Seven",
    "Eight",
    "Nine",
  ];
  const tens = [
    "",
    "",
    "Twenty",
    "Thirty",
    "Forty",
    "Fifty",
    "Sixty",
    "Seventy",
    "Eighty",
    "Ninety",
  ];
  const teens = [
    "Ten",
    "Eleven",
    "Twelve",
    "Thirteen",
    "Fourteen",
    "Fifteen",
    "Sixteen",
    "Seventeen",
    "Eighteen",
    "Nineteen",
  ];

  function convertLessThanThousand(n) {
    if (n < 10) return ones[n];
    if (n < 20) return teens[n - 10];
    if (n < 100)
      return tens[Math.floor(n / 10)] + (n % 10 ? " " + ones[n % 10] : "");
    return (
      ones[Math.floor(n / 100)] +
      " Hundred" +
      (n % 100 ? " and " + convertLessThanThousand(n % 100) : "")
    );
  }

  if (num === 0) return "Zero";

  const crores = Math.floor(num / 10000000);
  const lakhs = Math.floor((num % 10000000) / 100000);
  const thousands = Math.floor((num % 100000) / 1000);
  const remainder = num % 1000;

  let result = "";
  if (crores) result += convertLessThanThousand(crores) + " Crore ";
  if (lakhs) result += convertLessThanThousand(lakhs) + " Lakh ";
  if (thousands) result += convertLessThanThousand(thousands) + " Thousand ";
  if (remainder) result += convertLessThanThousand(remainder);

  return result.trim() + " Only";
}
