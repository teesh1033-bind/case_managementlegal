(function (global) {
    'use strict';

    var MIN_LENGTH = 8;
    var MAX_LENGTH = 128;

    function validatePassword(password) {
        var errors = [];

        if (!password || password.length < MIN_LENGTH) {
            errors.push('Password must be at least ' + MIN_LENGTH + ' characters long.');
        }

        if (password && password.length > MAX_LENGTH) {
            errors.push('Password must not exceed ' + MAX_LENGTH + ' characters.');
        }

        if (password && !/[A-Z]/.test(password)) {
            errors.push('Password must include at least one uppercase letter.');
        }

        if (password && !/[a-z]/.test(password)) {
            errors.push('Password must include at least one lowercase letter.');
        }

        return { valid: errors.length === 0, errors: errors };
    }

    function validatePairDetailed(password, confirm) {
        var passwordErrors = [];
        var confirmError = '';

        if (!password) {
            passwordErrors.push('Password is required.');
        } else {
            passwordErrors = validatePassword(password).errors;
        }

        if (!confirm) {
            confirmError = 'Please confirm your password.';
        } else if (password !== confirm) {
            confirmError = 'Passwords do not match.';
        }

        return {
            valid: passwordErrors.length === 0 && password !== '' && confirmError === '',
            passwordErrors: passwordErrors,
            confirmError: confirmError
        };
    }

    function validateOptionalUpdateDetailed(password, confirm) {
        if (!password && !confirm) {
            return { valid: true, passwordErrors: [], confirmError: '' };
        }

        var passwordErrors = [];
        var confirmError = '';

        if (!password) {
            passwordErrors.push('Enter a new password.');
        } else {
            passwordErrors = validatePassword(password).errors;
        }

        if (!confirm) {
            confirmError = 'Confirm your new password.';
        } else if (password && password !== confirm) {
            confirmError = 'Passwords do not match.';
        }

        return {
            valid: passwordErrors.length === 0 && confirmError === '',
            passwordErrors: passwordErrors,
            confirmError: confirmError
        };
    }

    function getFieldContainer(input) {
        if (!input) {
            return null;
        }
        return input.closest('.form-group') || input.closest('.mb-3') || input.parentElement;
    }

    function clearFieldError(input) {
        if (!input) {
            return;
        }

        input.classList.remove('is-invalid');
        var container = getFieldContainer(input);
        if (!container) {
            return;
        }

        container.querySelectorAll('.legalpro-field-error').forEach(function (node) {
            node.remove();
        });
    }

    function setFieldError(input, message) {
        if (!input) {
            return;
        }

        clearFieldError(input);

        if (!message || (Array.isArray(message) && message.length === 0)) {
            return;
        }

        input.classList.add('is-invalid');
        var container = getFieldContainer(input);
        if (!container) {
            return;
        }

        var div = document.createElement('div');
        div.className = 'legalpro-field-error text-danger text-xs mt-1 d-block';

        if (Array.isArray(message)) {
            if (message.length === 1) {
                div.textContent = message[0];
            } else {
                var list = document.createElement('ul');
                list.className = 'mb-0 ps-3 text-start';
                message.forEach(function (item) {
                    var li = document.createElement('li');
                    li.textContent = item;
                    list.appendChild(li);
                });
                div.appendChild(list);
            }
        } else {
            div.textContent = message;
        }

        container.appendChild(div);
    }

    function applyPairErrors(passwordInput, confirmInput, result) {
        setFieldError(passwordInput, result.passwordErrors || []);
        setFieldError(confirmInput, result.confirmError || '');
        return result.valid;
    }

    function bindFieldValidation(passwordInput, confirmInput, optional) {
        if (!passwordInput) {
            return;
        }

        var validate = function () {
            var password = passwordInput.value || '';
            var confirm = confirmInput ? (confirmInput.value || '') : '';
            var result = optional
                ? validateOptionalUpdateDetailed(password, confirm)
                : validatePairDetailed(password, confirm);
            applyPairErrors(passwordInput, confirmInput, result);
        };

        passwordInput.addEventListener('input', function () {
            clearFieldError(passwordInput);
            if (confirmInput) {
                clearFieldError(confirmInput);
            }
        });

        if (confirmInput) {
            confirmInput.addEventListener('input', function () {
                clearFieldError(confirmInput);
            });
        }

        passwordInput.addEventListener('blur', validate);
        if (confirmInput) {
            confirmInput.addEventListener('blur', validate);
        }
    }

    function focusFirstInvalid(form) {
        var invalid = form.querySelector('.is-invalid');
        if (invalid) {
            invalid.focus();
        }
    }

    function validatePairOnSubmit(passwordInput, confirmInput, optional) {
        var password = passwordInput ? (passwordInput.value || '') : '';
        var confirm = confirmInput ? (confirmInput.value || '') : '';
        var result = optional
            ? validateOptionalUpdateDetailed(password, confirm)
            : validatePairDetailed(password, confirm);
        applyPairErrors(passwordInput, confirmInput, result);
        return result.valid;
    }

    function attachLawyerSaveForm(form) {
        if (!form) {
            return;
        }

        var createPassword = form.querySelector('[name="new_password"]');
        var createConfirm = form.querySelector('[name="new_password_confirm"]');
        var updatePassword = form.querySelector('[name="update_password"]');
        var updateConfirm = form.querySelector('[name="confirm_password"]');

        bindFieldValidation(createPassword, createConfirm, false);
        bindFieldValidation(updatePassword, updateConfirm, true);

        form.addEventListener('submit', function (event) {
            var valid = true;
            var createForm = document.getElementById('create_user_form');
            var isCreatingUser = createForm && createForm.style.display !== 'none'
                && form.querySelector('[name="new_username"]')
                && String(form.querySelector('[name="new_username"]').value || '').trim() !== '';

            var updateSection = document.getElementById('user_update_section');
            var isUpdatingUser = updateSection && updateSection.style.display !== 'none';

            if (isCreatingUser && createPassword) {
                if (!validatePairOnSubmit(createPassword, createConfirm, false)) {
                    valid = false;
                }
            }

            if (isUpdatingUser && updatePassword) {
                if (!validatePairOnSubmit(updatePassword, updateConfirm, true)) {
                    valid = false;
                }
            }

            if (!valid) {
                event.preventDefault();
                focusFirstInvalid(form);
            }
        });
    }

    function attachClientDetailForm(form) {
        if (!form) {
            return;
        }

        var createPassword = form.querySelector('[name="password"]');
        var createConfirm = form.querySelector('[name="password_confirm"]');
        var updatePassword = form.querySelector('[name="update_password"]');
        var updateConfirm = form.querySelector('[name="confirm_password"]');

        bindFieldValidation(createPassword, createConfirm, false);
        bindFieldValidation(updatePassword, updateConfirm, true);

        form.addEventListener('submit', function (event) {
            var valid = true;
            var createCheckbox = document.getElementById('create_user_account');
            var createEnabled = createCheckbox && createCheckbox.checked;

            if (createEnabled && createPassword) {
                if (!validatePairOnSubmit(createPassword, createConfirm, false)) {
                    valid = false;
                }
            }

            if (updatePassword) {
                if (!validatePairOnSubmit(updatePassword, updateConfirm, true)) {
                    valid = false;
                }
            }

            if (!valid) {
                event.preventDefault();
                focusFirstInvalid(form);
            }
        });
    }

    function attachSimplePairForm(form, passwordName, confirmName) {
        if (!form) {
            return;
        }

        var passwordInput = form.querySelector('[name="' + passwordName + '"]');
        var confirmInput = form.querySelector('[name="' + confirmName + '"]');
        bindFieldValidation(passwordInput, confirmInput, false);

        form.addEventListener('submit', function (event) {
            if (!validatePairOnSubmit(passwordInput, confirmInput, false)) {
                event.preventDefault();
                focusFirstInvalid(form);
            }
        });
    }

    function attachOptionalPairForm(form, passwordName, confirmName) {
        if (!form) {
            return;
        }

        var passwordInput = form.querySelector('[name="' + passwordName + '"]');
        var confirmInput = form.querySelector('[name="' + confirmName + '"]');
        bindFieldValidation(passwordInput, confirmInput, true);

        form.addEventListener('submit', function (event) {
            if (!validatePairOnSubmit(passwordInput, confirmInput, true)) {
                event.preventDefault();
                focusFirstInvalid(form);
            }
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.legalpro-field-error').forEach(function (errorNode) {
            var container = errorNode.closest('.form-group') || errorNode.closest('.mb-3');
            if (!container) {
                return;
            }
            var input = container.querySelector('input[type="password"]');
            if (input) {
                input.classList.add('is-invalid');
            }
        });
    });

    global.LegalProPassword = {
        validatePassword: validatePassword,
        validatePairDetailed: validatePairDetailed,
        validateOptionalUpdateDetailed: validateOptionalUpdateDetailed,
        setFieldError: setFieldError,
        clearFieldError: clearFieldError,
        applyPairErrors: applyPairErrors,
        attachLawyerSaveForm: attachLawyerSaveForm,
        attachClientDetailForm: attachClientDetailForm,
        attachSimplePairForm: attachSimplePairForm,
        attachOptionalPairForm: attachOptionalPairForm
    };
})(window);
