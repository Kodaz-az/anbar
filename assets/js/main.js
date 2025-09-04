/**
 * AlumPro.az - Main JavaScript
 * Created: 2025-09-02
 */

document.addEventListener('DOMContentLoaded', function() {
    // User menu toggle
    const userInfo = document.querySelector('.user-info');
    if (userInfo) {
        userInfo.addEventListener('click', function(e) {
            e.stopPropagation();
            this.classList.toggle('open');
        });
        
        // Close when clicking outside
        document.addEventListener('click', function() {
            userInfo.classList.remove('open');
        });
    }
    
    // Mobile nav toggle
    const mobileNavToggle = document.querySelector('.mobile-nav-toggle');
    const navLinks = document.querySelector('.nav-links');
    
    if (mobileNavToggle && navLinks) {
        mobileNavToggle.addEventListener('click', function() {
            navLinks.classList.toggle('show');
            this.classList.toggle('active');
        });
    }
    
    // Modal functionality
    const modalTriggers = document.querySelectorAll('[data-toggle="modal"]');
    const modalCloseButtons = document.querySelectorAll('[data-dismiss="modal"]');
    const modalBackdrops = document.querySelectorAll('.modal-backdrop');
    
    modalTriggers.forEach(trigger => {
        trigger.addEventListener('click', function() {
            const targetModalId = this.getAttribute('data-target');
            const modal = document.querySelector(targetModalId);
            if (modal) {
                modal.classList.add('show');
            }
        });
    });
    
    modalCloseButtons.forEach(button => {
        button.addEventListener('click', function() {
            const modal = this.closest('.modal');
            if (modal) {
                modal.classList.remove('show');
            }
        });
    });
    
    modalBackdrops.forEach(backdrop => {
        backdrop.addEventListener('click', function() {
            const modal = this.closest('.modal');
            if (modal) {
                modal.classList.remove('show');
            }
        });
    });
    
    // Tooltips
    const tooltips = document.querySelectorAll('[data-tooltip]');
    
    tooltips.forEach(tooltip => {
        tooltip.addEventListener('mouseenter', function() {
            const text = this.getAttribute('data-tooltip');
            const tooltipElement = document.createElement('div');
            tooltipElement.className = 'tooltip';
            tooltipElement.textContent = text;
            document.body.appendChild(tooltipElement);
            
            const rect = this.getBoundingClientRect();
            const tooltipRect = tooltipElement.getBoundingClientRect();
            
            tooltipElement.style.top = (rect.top - tooltipRect.height - 10) + 'px';
            tooltipElement.style.left = (rect.left + (rect.width / 2) - (tooltipRect.width / 2)) + 'px';
            tooltipElement.style.opacity = '1';
            
            this.addEventListener('mouseleave', function() {
                tooltipElement.remove();
            });
        });
    });
    
    // Form validation
    const forms = document.querySelectorAll('form.needs-validation');
    
    forms.forEach(form => {
        form.addEventListener('submit', function(event) {
            if (!form.checkValidity()) {
                event.preventDefault();
                event.stopPropagation();
            }
            
            form.classList.add('was-validated');
        });
    });
    
    // Dynamic form elements
    const dynamicFormContainers = document.querySelectorAll('.dynamic-form-container');
    
    dynamicFormContainers.forEach(container => {
        const addButton = container.querySelector('.add-item-btn');
        const template = container.querySelector('.item-template');
        const itemsContainer = container.querySelector('.items-container');
        
        if (addButton && template && itemsContainer) {
            addButton.addEventListener('click', function() {
                const newItem = template.cloneNode(true);
                newItem.classList.remove('item-template');
                newItem.style.display = 'block';
                
                const removeButton = newItem.querySelector('.remove-item-btn');
                if (removeButton) {
                    removeButton.addEventListener('click', function() {
                        newItem.remove();
                        updateItemIndexes(itemsContainer);
                    });
                }
                
                itemsContainer.appendChild(newItem);
                updateItemIndexes(itemsContainer);
            });
            
            // Initialize existing items
            const existingItems = itemsContainer.querySelectorAll('.form-item:not(.item-template)');
            existingItems.forEach(item => {
                const removeButton = item.querySelector('.remove-item-btn');
                if (removeButton) {
                    removeButton.addEventListener('click', function() {
                        item.remove();
                        updateItemIndexes(itemsContainer);
                    });
                }
            });
        }
    });
    
    function updateItemIndexes(container) {
        const items = container.querySelectorAll('.form-item:not(.item-template)');
        items.forEach((item, index) => {
            const inputs = item.querySelectorAll('input, select, textarea');
            inputs.forEach(input => {
                const name = input.getAttribute('name');
                if (name) {
                    const newName = name.replace(/\[\d+\]/, '[' + index + ']');
                    input.setAttribute('name', newName);
                }
                
                const id = input.getAttribute('id');
                if (id) {
                    const newId = id.replace(/\_\d+$/, '_' + index);
                    input.setAttribute('id', newId);
                }
            });
            
            const labels = item.querySelectorAll('label');
            labels.forEach(label => {
                const forAttr = label.getAttribute('for');
                if (forAttr) {
                    const newFor = forAttr.replace(/\_\d+$/, '_' + index);
                    label.setAttribute('for', newFor);
                }
            });
        });
    }
    
    // Date and time formatting
    const dateElements = document.querySelectorAll('.format-date');
    
    dateElements.forEach(element => {
        const date = new Date(element.getAttribute('data-date'));
        const format = element.getAttribute('data-format') || 'date';
        
        if (format === 'date') {
            element.textContent = date.toLocaleDateString('az-AZ');
        } else if (format === 'time') {
            element.textContent = date.toLocaleTimeString('az-AZ', {hour: '2-digit', minute: '2-digit'});
        } else if (format === 'datetime') {
            element.textContent = date.toLocaleDateString('az-AZ') + ' ' + 
                                date.toLocaleTimeString('az-AZ', {hour: '2-digit', minute: '2-digit'});
        } else if (format === 'relative') {
            element.textContent = getRelativeTimeString(date);
        }
    });
    
    function getRelativeTimeString(date) {
        const now = new Date();
        const diffMs = now - date;
        const diffSec = Math.round(diffMs / 1000);
        const diffMin = Math.round(diffSec / 60);
        const diffHour = Math.round(diffMin / 60);
        const diffDay = Math.round(diffHour / 24);
        
        if (diffSec < 60) {
            return 'indicə';
        } else if (diffMin < 60) {
            return `${diffMin} dəqiqə əvvəl`;
        } else if (diffHour < 24) {
            return `${diffHour} saat əvvəl`;
        } else if (diffDay < 7) {
            return `${diffDay} gün əvvəl`;
        } else {
            return date.toLocaleDateString('az-AZ');
        }
    }
    
    // Number formatting
    const numberElements = document.querySelectorAll('.format-number');
    
    numberElements.forEach(element => {
        const number = parseFloat(element.getAttribute('data-number'));
        const format = element.getAttribute('data-format') || 'decimal';
        
        if (format === 'decimal') {
            element.textContent = number.toLocaleString('az-AZ', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        } else if (format === 'currency') {
            element.textContent = number.toLocaleString('az-AZ', {
                style: 'currency',
                currency: 'AZN',
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        } else if (format === 'percent') {
            element.textContent = (number * 100).toLocaleString('az-AZ', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            }) + '%';
        }
    });
    
    // Phone number formatting
    const phoneInputs = document.querySelectorAll('input[type="tel"]');
    
    phoneInputs.forEach(input => {
        input.addEventListener('input', function(e) {
            let value = e.target.value.replace(/\D/g, '');
            
            if (value.length > 0) {
                if (value.length <= 3) {
                    value = '+' + value;
                } else if (value.length <= 5) {
                    value = '+' + value.substring(0, 3) + ' ' + value.substring(3);
                } else if (value.length <= 8) {
                    value = '+' + value.substring(0, 3) + ' ' + value.substring(3, 5) + ' ' + value.substring(5);
                } else if (value.length <= 10) {
                    value = '+' + value.substring(0, 3) + ' ' + value.substring(3, 5) + ' ' + value.substring(5, 8) + ' ' + value.substring(8);
                } else {
                    value = '+' + value.substring(0, 3) + ' ' + value.substring(3, 5) + ' ' + value.substring(5, 8) + ' ' + value.substring(8, 10) + ' ' + value.substring(10);
                }
            }
            
            e.target.value = value;
        });
    });
    
    // Custom selects
    const customSelects = document.querySelectorAll('.custom-select');
    
    customSelects.forEach(select => {
        const selectWrapper = document.createElement('div');
        selectWrapper.className = 'custom-select-wrapper';
        
        const selectedOption = document.createElement('div');
        selectedOption.className = 'custom-select-selected';
        selectedOption.textContent = select.options[select.selectedIndex].text;
        
        const optionsList = document.createElement('div');
        optionsList.className = 'custom-select-options';
        
        Array.from(select.options).forEach((option, index) => {
            const optionItem = document.createElement('div');
            optionItem.className = 'custom-select-option';
            optionItem.textContent = option.text;
            optionItem.dataset.value = option.value;
            
            if (index === select.selectedIndex) {
                optionItem.classList.add('selected');
            }
            
            optionItem.addEventListener('click', function() {
                select.value = this.dataset.value;
                selectedOption.textContent = this.textContent;
                
                const event = new Event('change', { bubbles: true });
                select.dispatchEvent(event);
                
                optionsList.querySelectorAll('.custom-select-option').forEach(opt => {
                    opt.classList.remove('selected');
                });
                this.classList.add('selected');
                
                selectWrapper.classList.remove('open');
            });
            
            optionsList.appendChild(optionItem);
        });
        
        selectedOption.addEventListener('click', function(e) {
            e.stopPropagation();
            selectWrapper.classList.toggle('open');
        });
        
        document.addEventListener('click', function() {
            selectWrapper.classList.remove('open');
        });
        
        selectWrapper.appendChild(selectedOption);
        selectWrapper.appendChild(optionsList);
        
        select.parentNode.insertBefore(selectWrapper, select.nextSibling);
        select.style.display = 'none';
        
        // Update when the select changes programmatically
        select.addEventListener('change', function() {
            selectedOption.textContent = this.options[this.selectedIndex].text;
            
            optionsList.querySelectorAll('.custom-select-option').forEach(opt => {
                opt.classList.remove('selected');
                if (opt.dataset.value === this.value) {
                    opt.classList.add('selected');
                }
            });
        });
    });
    
    // Alerts auto-close
    const autoCloseAlerts = document.querySelectorAll('.alert.auto-close');
    
    autoCloseAlerts.forEach(alert => {
        const duration = parseInt(alert.getAttribute('data-duration') || '5000');
        
        setTimeout(() => {
            alert.style.opacity = '0';
            setTimeout(() => {
                alert.remove();
            }, 300);
        }, duration);
    });
    
    // Initialize any specific page functionality
    const currentPage = document.body.getAttribute('data-page');
    
    if (currentPage) {
        if (typeof window[`init${currentPage}Page`] === 'function') {
            window[`init${currentPage}Page`]();
        }
    }
    
    // Initialize chart.js charts if available
    if (typeof Chart !== 'undefined') {
        Chart.defaults.font.family = "'Roboto', 'Helvetica Neue', 'Helvetica', 'Arial', sans-serif";
        Chart.defaults.color = '#6b7280';
        Chart.defaults.scale.grid.color = 'rgba(211, 211, 211, 0.3)';
    }
    
    // AlumPro logo display in console for developers
    console.log(`
    █████╗ ██╗     ██╗   ██╗███╗   ███╗██████╗ ██████╗  ██████╗ 
   ██╔══██╗██║     ██║   ██║████╗ ████║██╔══██╗██╔══██╗██╔═══██╗
   ███████║██║     ██║   ██║██╔████╔██║██████╔╝██████╔╝██║   ██║
   ██╔══██║██║     ██║   ██║██║╚██╔╝██║██╔═══╝ ██╔══██╗██║   ██║
   ██║  ██║███████╗╚██████╔╝██║ ╚═╝ ██║██║     ██║  ██║╚██████╔╝
   ╚═╝  ╚═╝╚══════╝ ╚═════╝ ╚═╝     ╚═╝╚═╝     ╚═╝  ╚═╝ ╚═════╝ 
   
   AlumPro.az - Alüminium və Şüşə Menecement Sistemi
   Version: 1.0.0
   Logged in as: ${document.body.getAttribute('data-user') || 'Guest'}
   Build date: 2025-09-02
   `);
});