@props([
    'label',
    'name',
    'options' => [],
    'value' => '',
    'placeholder' => 'Ketik untuk mencari…',
    'required' => false,
    'emptyText' => 'Tidak ada hasil',
])

@php
    $normalized = collect($options)
        ->map(fn ($optionLabel, $optionValue) => [
            'value' => (string) $optionValue,
            'label' => (string) $optionLabel,
        ])
        ->values()
        ->all();

    $selectedValue = (string) old($name, $value);
    $selectedLabel = collect($normalized)->firstWhere('value', $selectedValue)['label'] ?? '';
@endphp

<div
    {{ $attributes->class('ui-autocomplete') }}
    x-data="{
        options: @js($normalized),
        value: @js($selectedValue),
        query: @js($selectedLabel),
        open: false,
        highlight: -1,
        get filtered() {
            const term = this.query.trim().toLowerCase();
            if (! term) return this.options.slice(0, 80);
            return this.options
                .filter((row) => row.label.toLowerCase().includes(term) || row.value.toLowerCase().includes(term))
                .slice(0, 80);
        },
        syncLabelFromValue() {
            const match = this.options.find((row) => row.value === String(this.value));
            this.query = match ? match.label : '';
        },
        onQuery() {
            const match = this.options.find((row) => row.label === this.query);
            if (! match) {
                this.value = '';
                this.emitChange();
            }
            this.highlight = this.filtered.length ? 0 : -1;
        },
        select(option) {
            this.value = option.value;
            this.query = option.label;
            this.open = false;
            this.highlight = -1;
            this.emitChange();
        },
        selectHighlighted() {
            if (this.highlight < 0 || ! this.filtered[this.highlight]) return;
            this.select(this.filtered[this.highlight]);
        },
        highlightNext() {
            if (! this.filtered.length) return;
            this.open = true;
            this.highlight = (this.highlight + 1) % this.filtered.length;
        },
        highlightPrev() {
            if (! this.filtered.length) return;
            this.open = true;
            this.highlight = this.highlight <= 0 ? this.filtered.length - 1 : this.highlight - 1;
        },
        emitChange() {
            this.$nextTick(() => {
                this.$refs.hidden?.dispatchEvent(new Event('change', { bubbles: true }));
                this.$refs.hidden?.dispatchEvent(new Event('input', { bubbles: true }));
            });
        },
        clear() {
            this.value = '';
            this.query = '';
            this.open = false;
            this.highlight = -1;
            this.emitChange();
            this.$refs.search?.focus();
        }
    }"
    @keydown.escape.window="open = false"
>
    <flux:field>
        <flux:label>
            {{ $label }}
            @if ($required)
                <x-required-mark />
            @endif
        </flux:label>

        <div class="ui-autocomplete-control" @click.outside="open = false">
            <input type="hidden" name="{{ $name }}" x-ref="hidden" x-model="value">

            <div class="ui-autocomplete-input-wrap">
                <input
                    type="text"
                    x-ref="search"
                    x-model="query"
                    @focus="open = true; highlight = filtered.length ? 0 : -1"
                    @input="open = true; onQuery()"
                    @keydown.arrow-down.prevent="highlightNext()"
                    @keydown.arrow-up.prevent="highlightPrev()"
                    @keydown.enter.prevent="selectHighlighted()"
                    @keydown.escape.prevent="open = false"
                    placeholder="{{ $placeholder }}"
                    autocomplete="off"
                    role="combobox"
                    aria-autocomplete="list"
                    :aria-expanded="open.toString()"
                    :aria-required="({{ $required ? 'true' : 'false' }} && !value) ? 'true' : 'false'"
                    :required="{{ $required ? 'true' : 'false' }} && !value"
                    class="ui-autocomplete-input"
                >

                <button
                    type="button"
                    class="ui-autocomplete-clear"
                    x-show="query.length"
                    x-cloak
                    @click="clear()"
                    aria-label="Hapus pilihan"
                >&times;</button>
            </div>

            <ul
                class="ui-autocomplete-menu"
                x-show="open"
                x-cloak
                x-transition.opacity.duration.120ms
                role="listbox"
            >
                <template x-for="(option, index) in filtered" :key="option.value">
                    <li
                        role="option"
                        class="ui-autocomplete-option"
                        :class="{ 'is-active': index === highlight, 'is-selected': option.value === value }"
                        :aria-selected="(option.value === value).toString()"
                        @mousedown.prevent="select(option)"
                        @mouseenter="highlight = index"
                        x-text="option.label"
                    ></li>
                </template>
                <li class="ui-autocomplete-empty" x-show="filtered.length === 0" x-text="@js($emptyText)"></li>
            </ul>
        </div>

        <flux:error name="{{ $name }}" />
    </flux:field>
</div>
