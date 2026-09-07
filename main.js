// main.js

// ==============================================
// تابع تشخیص مسیر پایه
// ==============================================
function getBasePath() {
    const path = window.location.pathname;
    const isInSubfolder = path.includes('/project/') || 
                          path.includes('/about/') || 
                          path.includes('/services/') || 
                          path.includes('/blog/') || 
                          path.includes('/contact/');
    return isInSubfolder ? '../' : '';
}

// ==============================================
// تابع بارگذاری favicon از تنظیمات سایت
// ==============================================
function loadFavicon() {
    const basePath = getBasePath();
    
    fetch(basePath + 'data/settings.json')
        .then(res => {
            if (!res.ok) throw new Error('Settings not found');
            return res.json();
        })
        .then(settings => {
            // حذف favicon های قبلی
            document.querySelectorAll('link[rel="icon"]').forEach(l => l.remove());
            document.querySelectorAll('link[rel="shortcut icon"]').forEach(l => l.remove());
            
            // ایجاد favicon جدید
            const link = document.createElement('link');
            link.rel = 'icon';
            
            if (settings.favicon && settings.favicon.trim() !== '') {
                link.href = basePath + settings.favicon;
            } else {
                // favicon پیش‌فرض
                link.href = basePath + 'assets/favicon.png';
            }
            
            document.head.appendChild(link);
        })
        .catch(() => {
            // اگر خطا شد، favicon پیش‌فرض
            document.querySelectorAll('link[rel="icon"]').forEach(l => l.remove());
            const link = document.createElement('link');
            link.rel = 'icon';
            link.href = getBasePath() + 'assets/favicon.png';
            document.head.appendChild(link);
        });
}

// ==============================================
// تابع بارگذاری لوگو در هدر (اختیاری)
// ==============================================
function loadLogo() {
    const basePath = getBasePath();
    
    fetch(basePath + 'data/settings.json')
        .then(res => res.json())
        .then(settings => {
            if (settings.logo && settings.logo.trim() !== '') {
                const logoBox = document.querySelector('.header__logo-box');
                if (logoBox) {
                    const existingLogo = logoBox.querySelector('.header__logo-img');
                    if (!existingLogo) {
                        const img = document.createElement('img');
                        img.src = basePath + settings.logo;
                        img.alt = settings.site_name || 'لوگو';
                        img.className = 'header__logo-img';
                        img.style.height = '40px';
                        img.style.width = 'auto';
                        logoBox.prepend(img);
                    }
                }
            }
        })
        .catch(() => {});
}

// ==============================================
// تابع اصلی برای بارگذاری کامپوننت‌ها (هدر و فوتر)
// ==============================================
function loadComponent(id, file, callback) {
    fetch(file)
        .then(res => res.text())
        .then(data => {
            const element = document.getElementById(id);
            if (element) {
                element.innerHTML = data;
                if (callback) callback();
            }
        })
        .catch(err => {
            console.error('خطا در بارگذاری:', file, err);
        });
}

// ==============================================
// تابع مدیریت ساعات کاری
// ==============================================
function updateWorkHours() {
    const hour = new Date().getHours();
    const workContent = document.querySelector('#workHoursContent');
    const offContent = document.querySelector('#offHoursContent');

    if (workContent && offContent) {
        if (hour >= 8 && hour < 18) {
            workContent.style.display = 'block';
            offContent.style.display = 'none';
        } else {
            workContent.style.display = 'none';
            offContent.style.display = 'block';
        }
    }
}

// ==============================================
// اجرای خودکار پس از لود شدن صفحه
// ==============================================
document.addEventListener('DOMContentLoaded', () => {
    // بارگذاری favicon
    loadFavicon();
    
    // بارگذاری لوگو (اختیاری)
    loadLogo();
    
    // بارگذاری هدر
    loadComponent('header-placeholder', getBasePath() + 'header.html', () => {
        const menuToggle = document.getElementById('menuToggle');
        const headerNav = document.getElementById('headerNav');
        if (menuToggle && headerNav) {
            menuToggle.addEventListener('click', () => {
                menuToggle.classList.toggle('is-active');
                headerNav.classList.toggle('is-open');
            });
        }
    });

    // بارگذاری فوتر و چک کردن ساعت کاری
    loadComponent('footer-placeholder', getBasePath() + 'footer.html', () => {
        updateWorkHours();
    });
});