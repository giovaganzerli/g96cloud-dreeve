import Clusterize from '../../../libraries/clusterize/clusterize.min';
import {SummableCalculator} from "./summable-calculator";

export class ClusterRenderer {
    constructor(wrapper, tbody, scrollElem) {
        this.wrapper = wrapper;
        this.tbody = tbody;
        this.scrollElem = scrollElem;
        this.cluster = null;
        this.dataRows = [];
    }

    init(dataRows) {
        this.dataRows = dataRows;

        if (!this.scrollElem) {
            // Without a scroll container there is nothing to virtualize: render every row.
            this.render();

            return;
        }

        this.cluster = new Clusterize({
            rows: dataRows.filter(r => r.active).map(r => r.markup),
            scrollElem: this.scrollElem,
            contentElem: this.tbody,
            no_data_class: 'clusterize-data-empty',
            no_data_text: 'No data found',
            callbacks: {
                clusterChanged: () => this.onChange(),
            }
        });
    }

    update(dataRows, resetScroll = true) {
        this.dataRows = dataRows;

        if (!this.cluster) {
            this.render();

            return;
        }

        this.cluster.update(dataRows.filter(r => r.active).map(r => r.markup));
        if (resetScroll) {
            this.scrollElem.scrollTop = 0;
        }
    }

    render() {
        this.tbody.innerHTML = this.dataRows.filter(r => r.active).map(r => r.markup).join('');
        this.onChange();
    }

    onChange() {
        SummableCalculator.render(this.wrapper, this.dataRows);

        const resultCount = this.wrapper.querySelector('[data-dataTable-result-count]');
        if (resultCount) resultCount.innerText = this.dataRows.filter(r => r.active).length;
    }
}
