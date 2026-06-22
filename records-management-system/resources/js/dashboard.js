function toggleDropdown() {
    const dropdownIcon = document.getElementById('dropdown-icon');
    const dropdown = document.getElementById('dropdown');

    if (!dropdown?.classList.contains('show')) {
        window.dispatchEvent(new CustomEvent('close-notifications'));
    }

    if (dropdownIcon?.classList.contains('rotate')) {
        dropdownIcon.classList.remove('rotate');
        dropdownIcon.classList.toggle('revert');
    } else {
        if (dropdownIcon?.classList.contains('revert')) {
            dropdownIcon.classList.remove('revert');
        }
        dropdownIcon?.classList.add('rotate');
    }

    dropdown?.classList.toggle('show');
}

function closeActionsDropdown() {
    const dropdown = document.getElementById('dropdown');
    const dropdownIcon = document.getElementById('dropdown-icon');
    if (dropdown?.classList.contains('show')) {
        dropdown.classList.remove('show');
        if (dropdownIcon?.classList.contains('rotate')) {
            dropdownIcon.classList.remove('rotate');
            dropdownIcon.classList.add('revert');
        }
    }
}

function toggleNavProperties() {
    const navigation = document.getElementById('navigation');
    const navMainIcon = document.getElementById('nav-main-icon');
    const articleContainer = document.getElementById('article-container');
    const toggleNavSection = window.assetPaths?.toggleNavSection ?? '/icons/toggle-nav-section.svg';
    const toggleNavDefault = window.assetPaths?.toggleNavDefault ?? '/icons/toggle-nav-default.svg';

    if (navigation.classList.contains('imup')) {
        navigation.classList.remove('imup');
        navigation.classList.add('imdown');
        articleContainer.classList.remove('imdown');
        articleContainer.classList.add('imup');
        navMainIcon.src = toggleNavSection;
    } else {
        navigation.classList.remove('imdown');
        navigation.classList.add('imup');
        articleContainer.classList.remove('imup');
        articleContainer.classList.add('imdown');
        navMainIcon.src = toggleNavDefault;
    }
}

function resetNavProperties() {
    const navigation = document.getElementById('navigation');
    const navMainIcon = document.getElementById('nav-main-icon');
    const articleContainer = document.getElementById('article-container');
    const toggleNavDefault = window.assetPaths?.toggleNavDefault ?? '/icons/toggle-nav-default.svg';

    navigation.classList.remove('imdown');
    navigation.classList.add('imup');
    articleContainer.classList.remove('imup');
    articleContainer.classList.add('imdown');
    navMainIcon.src = toggleNavDefault;
}

function showButtonSection(button_target) {
    const navigation = document.getElementById('navigation');
    const pla = document.getElementById(button_target);

    if (navigation.classList.contains('imup')) {
        if (pla.classList.contains('show')) {
            pla.classList.remove('show');
        } else {
            pla.classList.add('show');
        }
    }
}

function proccedto(url) {
    window.location.href = url;
}

window.toggleDropdown = toggleDropdown;
window.toggleNavProperties = toggleNavProperties;
window.resetNavProperties = resetNavProperties;
window.showButtonSection = showButtonSection;
window.proccedto = proccedto;
window.closeActionsDropdown = closeActionsDropdown;