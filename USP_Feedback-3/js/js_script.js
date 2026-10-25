// Form Validation
function validateComplaintForm() {
    const subject = document.getElementById('subject').value.trim();
    const category = document.getElementById('category').value;
    const message = document.getElementById('message').value.trim();
    
    if (subject === '') {
        alert('Please enter complaint subject');
        return false;
    }
    
    if (category === '') {
        alert('Please select a category');
        return false;
    }
    
    if (message === '') {
        alert('Please enter complaint message');
        return false;
    }
    
    if (message.length < 10) {
        alert('Message must be at least 10 characters');
        return false;
    }
    
    return true;
}

// Email Validation
function validateEmail(email) {
    const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    return re.test(email);
}

// Password Validation
function validatePassword(password) {
    return password.length >= 6;
}

// Form Submit Handler
document.addEventListener('DOMContentLoaded', function() {
    const complaintForm = document.getElementById('complaintForm');
    if (complaintForm) {
        complaintForm.addEventListener('submit', function(e) {
            if (!validateComplaintForm()) {
                e.preventDefault();
            }
        });
    }
});

// Tooltip Initialization
document.addEventListener('DOMContentLoaded', function() {
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function(tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
});

// Auto-hide alerts
document.addEventListener('DOMContentLoaded', function() {
    const alerts = document.querySelectorAll('.alert');
    alerts.forEach(alert => {
        setTimeout(function() {
            alert.style.transition = 'opacity 0.5s';
            alert.style.opacity = '0';
        }, 5000);
    });
});