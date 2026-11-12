import createDragSort from "../drag-sort";

const CUSTOM_STATISTIC_KEYS = ['label', 'value', 'unit', 'icon', 'category'];

const uniqueId = () => `custom-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 10)}`;

class CollectionStatisticsEditor {
    constructor(root) {
        this.root = root;
        this.list = root.querySelector('[data-statistics-list]');
        this.orderInput = root.querySelector('[data-statistics-order]');
        this.customInput = root.querySelector('[data-statistics-custom]');
        this.addButton = root.querySelector('[data-statistics-add]');
        this.template = root.querySelector('[data-statistics-template]');
    }

    init() {
        if (!this.list || !this.template) {
            return;
        }

        this.addButton?.addEventListener('click', () => this.addRow());
        this.list.addEventListener('click', (event) => {
            const removeButton = event.target.closest('[data-statistics-remove]');
            if (!removeButton) {
                return;
            }
            removeButton.closest('[data-sort-item]')?.remove();
            this.sync();
        });
        this.list.addEventListener('input', () => this.sync());

        createDragSort(this.list, {
            itemSelector: '[data-sort-item]',
            onReorder: () => this.sync(),
        }).catch((error) => console.error('Failed to initialise the sortable statistics list:', error));

        this.sync();
    }

    addRow() {
        const row = this.template.content.firstElementChild.cloneNode(true);
        const id = uniqueId();

        row.setAttribute('data-sort-id', id);
        row.setAttribute('data-custom-statistic', id);
        row.querySelector('[data-custom-statistic-id]').value = id;

        this.list.appendChild(row);
        this.sync();
    }

    // The custom statistics are sent as a single JSON payload, so the field names of the
    // rows don't have to be reindexed when they are reordered.
    sync() {
        const order = Array.from(this.list.querySelectorAll('[data-sort-item]'))
            .map((item) => item.getAttribute('data-sort-id'))
            .filter((id) => id);
        if (this.orderInput) {
            this.orderInput.value = JSON.stringify(order);
        }

        const customStatistics = Array.from(this.list.querySelectorAll('[data-custom-statistic]'))
            .map((row) => {
                const statistic = {id: row.getAttribute('data-custom-statistic')};
                CUSTOM_STATISTIC_KEYS.forEach((key) => {
                    const field = row.querySelector(`[data-custom-statistic-${key}]`);
                    statistic[key] = field ? field.value : '';
                });

                return statistic;
            })
            .filter((statistic) => statistic.label);
        if (this.customInput) {
            this.customInput.value = JSON.stringify(customStatistics);
        }
    }
}

export default function initCollectionStatisticsEditors(rootNode = document) {
    rootNode.querySelectorAll('[data-statistics-editor]').forEach((root) => {
        if (root.getAttribute('data-initialised') === 'true') {
            return;
        }
        root.setAttribute('data-initialised', 'true');
        new CollectionStatisticsEditor(root).init();
    });
}
