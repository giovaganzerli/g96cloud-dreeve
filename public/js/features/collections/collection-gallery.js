import {basePath, fetchJson} from "../../utils";

export default class CollectionGallery {
    constructor(root) {
        this.root = root;
        this.url = root.getAttribute('data-gallery-url');
        this.photos = root.querySelector('[data-gallery-photos]');
        this.albumLink = root.querySelector('[data-gallery-album-link]');
    }

    async init() {
        if (!this.url || !this.photos) {
            return;
        }

        try {
            const gallery = await fetchJson(this.url);
        } catch (error) {
            console.error('Failed to load the collection gallery:', error);
            return;
        }

        if (gallery.albumUrl) {
            this.albumLink?.classList.remove('hidden');
            this.albumLink?.setAttribute('href', gallery.albumUrl);
        }

        (gallery.photos ?? []).forEach((photo) => {
            const link = document.createElement('a');
            link.href = basePath() + photo.url;
            link.className = 'block overflow-hidden rounded-lg';

            const image = document.createElement('img');
            image.src = basePath() + photo.url;
            image.alt = '';
            image.loading = 'lazy';
            image.className = 'h-36 w-full object-cover transition duration-300 group-hover:scale-105';

            link.appendChild(image);
            this.photos.appendChild(link);
        });
    }
}
