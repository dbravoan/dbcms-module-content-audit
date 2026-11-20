<x-admin.form-card>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h2 class="admin-card-title">{{ __('content_audit::comparison.title') }}</h2>
        <x-admin.button type="button" icon="fa-solid fa-code-compare"
                        data-audit-comparison-open
                        @click="$dispatch('open-audit-comparison')"
                        :disabled="$previewMarkets === []">
            {{ __('content_audit::comparison.open') }}
        </x-admin.button>
    </div>
    <p class="admin-text-muted mt-2">{{ __('content_audit::comparison.description') }}</p>
    @if($previewMarkets === [])
        <p class="admin-field-hint" role="status">{{ __('content_audit::comparison.unavailable') }}</p>
    @endif
</x-admin.form-card>

@push('admin_modals')
<div x-data="{
        comparisonOpen: false,
        markets: @js($previewMarkets),
        market: @js($previewMarket),
        locale: @js($previewLocale),
        base: @js(route('admin.audit.preview', ['type' => $type, 'id' => $id]) . '?' . http_build_query($previewParams)),
        url(side) {
            return this.base + '&side=' + side + '&market=' + encodeURIComponent(this.market) + '&locale=' + encodeURIComponent(this.locale);
        },
        changeMarket() {
            this.locale = this.markets.find(item => item.id === this.market)?.languages[0]?.code || '';
        }
    }"
    @open-audit-comparison.window="comparisonOpen = true">
    <x-admin.modal show="comparisonOpen"
                   :title="__('content_audit::comparison.title')"
                   max-width="7xl"
                   body-class="min-h-0"
                   data-audit-comparison>
        <x-slot:toolbar>
            <div class="grid grid-cols-2 gap-3 w-full">
                <x-admin.select name="audit_preview_market" id="audit-preview-market"
                                :label="__('content_audit::comparison.market')"
                                :value="$previewMarket" :error="false"
                                x-model="market" @change="changeMarket()"
                                data-admin-dialog-initial-focus>
                    <template x-for="item in markets" :key="item.id">
                        <option :value="item.id" x-text="item.name"></option>
                    </template>
                </x-admin.select>
                <x-admin.select name="audit_preview_locale" id="audit-preview-locale"
                                :label="__('content_audit::comparison.language')"
                                :value="$previewLocale" :error="false"
                                x-model="locale">
                    <template x-for="item in (markets.find(item => item.id === market)?.languages || [])" :key="item.code">
                        <option :value="item.code" x-text="item.name"></option>
                    </template>
                </x-admin.select>
            </div>
        </x-slot:toolbar>
        <template x-if="comparisonOpen">
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 h-full">
                @foreach(['before', 'after'] as $side)
                    <section class="min-w-0 min-h-0 flex flex-col gap-2" aria-label="{{ __('content_audit::comparison.'.$side) }}">
                        <h3 class="admin-card-title">{{ __('content_audit::comparison.'.$side) }}</h3>
                        <iframe title="{{ __('content_audit::comparison.'.$side) }}"
                                data-cms-preview-frame
                                data-audit-preview="{{ $side }}"
                                :src="url('{{ $side }}')"
                                sandbox="allow-scripts"
                                class="block w-full h-[55dvh] min-h-64 border-0 bg-white flex-1"></iframe>
                    </section>
                @endforeach
            </div>
        </template>
    </x-admin.modal>
</div>
@endpush
