import {fetchJson} from "../../utils";

// Fills a select with the options of an endpoint, so the options don't have to be
// rendered on the page upfront.
export default function initAsyncSelects(rootNode = document) {
    rootNode.querySelectorAll('select[data-async-select-url]').forEach((select) => {
        if (select.getAttribute('data-initialised') === 'true') {
            return;
        }
        select.setAttribute('data-initialised', 'true');

        const url = select.getAttribute('data-async-select-url');
        const placeholder = select.getAttribute('data-async-select-placeholder') ?? '';
        const currentValue = select.value;

        fetchJson(url)
            .then((options) => {
                select.textContent = '';

                const empty = document.createElement('option');
                empty.value = '';
                empty.textContent = placeholder;
                select.appendChild(empty);

                (options ?? []).forEach((option) => {
                    const element = document.createElement('option');
                    element.value = option.value;
                    element.textContent = option.label;
                    select.appendChild(element);
                });

                if (currentValue) {
                    select.value = currentValue;
                }
            })
            .catch((error) => console.error('Failed to load options:', error));
    });
}
