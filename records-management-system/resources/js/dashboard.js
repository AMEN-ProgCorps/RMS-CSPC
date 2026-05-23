function toggleDropdown() {
    const dropdownIcon = document.getElementById('dropdown-icon');
    dropdownIcon.classList.toggle('rotate');
    const dropdown = document.getElementById('dropdown');
    dropdown.classList.toggle('show');
}