document.querySelectorAll('.mp-gallery-slider').forEach(slider => {
    const photos = [...slider.querySelectorAll('img')];
    const controls = slider.querySelector('.mp-gallery-slide-controls');
    if (!controls) return;
    controls.hidden = false;
    const dots = [...controls.querySelectorAll('[data-photo]')];
    let current = 0;
    const show = index => {
        current = (index + photos.length) % photos.length;
        photos.forEach((photo, i) => { photo.hidden = i !== current; });
        dots.forEach((dot, i) => dot.setAttribute('aria-pressed', String(i === current)));
        controls.querySelector('[aria-live]').textContent = `Photo ${current + 1} sur ${photos.length}`;
    };
    controls.querySelectorAll('[data-slide]').forEach(button => button.addEventListener('click', () => show(current + Number(button.dataset.slide))));
    dots.forEach(button => button.addEventListener('click', () => show(Number(button.dataset.photo))));
    let start = null;
    slider.addEventListener('touchstart', event => { start = {x:event.touches[0].clientX, y:event.touches[0].clientY}; }, {passive:true});
    slider.addEventListener('touchend', event => {
        if (!start) return;
        const dx = event.changedTouches[0].clientX - start.x;
        const dy = event.changedTouches[0].clientY - start.y;
        if (Math.abs(dx) > 50 && Math.abs(dx) > Math.abs(dy)) show(current + (dx < 0 ? 1 : -1));
        start = null;
    }, {passive:true});
    slider.addEventListener('touchcancel', () => { start = null; }, {passive:true});
});

document.querySelectorAll('.mp-gallery-map').forEach(element => {
    const init = () => {
        if (!window.L || element.dataset.ready) return;
        element.dataset.ready = '1';
        const points = JSON.parse(element.dataset.points || '[]');
        const map = L.map(element, {scrollWheelZoom:false});
        L.tileLayer(element.dataset.tiles, {maxZoom:19, attribution:'&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>', referrerPolicy:'strict-origin-when-cross-origin'}).addTo(map);
        const groups = new Map();
        points.forEach(point => { const key = `${point.lat},${point.lon}`; if (!groups.has(key)) groups.set(key, []); groups.get(key).push(point); });
        const bounds = [];
        groups.forEach(group => {
            const point = group[0]; bounds.push([point.lat, point.lon]);
            const popup = document.createElement('div'); popup.className = 'mp-map-popup';
            const title = document.createElement('strong'); title.textContent = point.address; popup.append(title);
            const list = document.createElement('ul');
            group.forEach(item => { const li = document.createElement('li'); const link = document.createElement('a'); link.href = `#${item.target}`; link.textContent = item.name; li.append(link); list.append(li); });
            popup.append(list);
            L.marker([point.lat, point.lon], {title:`${point.city} — ${group.length} potier(s)`, alt:`${point.address} : ${group.length} potier(s)`, icon:L.divIcon({className:'mp-map-pin', html:`<span>${group.length > 1 ? group.length : '•'}</span>`,iconSize:[34,34],iconAnchor:[17,17]})}).addTo(map).bindPopup(popup);
        });
        if (bounds.length) map.fitBounds(bounds, {padding:[35,35],maxZoom:13});
        else map.setView([46.6,2.4],5);
    };
    if ('IntersectionObserver' in window) { const observer = new IntersectionObserver(entries => { if (entries.some(entry => entry.isIntersecting)) { init(); observer.disconnect(); } }); observer.observe(element); }
    else init();
});
