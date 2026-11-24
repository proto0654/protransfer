<?php
if (!defined('ABSPATH') || !current_user_can('manage_options')) {
    exit;
}
?>
<div class="container-x pb-10" x-cloak>
  <div class="card p-5 sm:p-6">
    <h2 class="text-lg font-extrabold">Как рассчитана цена на сайте</h2>
    <p class="mt-1 text-sm text-ink/60">Результат для текущего маршрута и тарифа. Заявки в тестовом режиме отключены.</p>
    <p class="mt-3 text-sm" x-show="!labBreakdown">Выберите две разные точки и тариф.</p>
    <template x-if="labBreakdown">
      <div class="mt-4 space-y-2 text-sm">
        <p><strong>Источник расстояния:</strong> <span x-text="labDistanceSource"></span> <span x-show="routeLoading">— уточняем маршрут…</span></p>
        <p><strong>Расстояние и время:</strong> <span x-text="`${routeInfo.km} км · ${durationLabel}`"></span></p>
        <p><strong>Тариф:</strong> <span x-text="selectedTariff.name"></span></p>
        <p><strong>Ставка за км:</strong> <span x-text="`${money(labBreakdown.perKm)} (${labBreakdown.perKmSource === 'route' ? 'цена маршрута' : 'тариф'})`"></span></p>
        <p><strong>Формула:</strong> <span x-text="`${money(selectedTariff.base)} + ${money(labBreakdown.perKm)} × ${labBreakdown.km} км = ${money(labBreakdown.formula)}`"></span></p>
        <p><strong>Минимальная стоимость:</strong> <span x-text="money(labBreakdown.minimum)"></span></p>
        <p><strong>После применения минимума:</strong> <span x-text="money(labBreakdown.basePrice)"></span></p>
        <p><strong>Туда и обратно:</strong> <span x-text="`× ${labBreakdown.multiplier}`"></span></p>
        <p><strong>Дополнительная остановка:</strong> <span x-text="money(labBreakdown.extraStop)"></span></p>
        <p><strong>Детское кресло:</strong> <span x-text="money(labBreakdown.childSeat)"></span></p>
        <p><strong>До округления:</strong> <span x-text="money(labBreakdown.beforeRounding)"></span></p>
        <p><strong>Округление:</strong> <span x-text="`до ${money(labBreakdown.roundStep)}`"></span></p>
        <p class="border-t border-ink/10 pt-2 text-base"><strong>Итого:</strong> <span x-text="labBreakdown.onRequest ? 'По запросу' : money(labBreakdown.total)"></span></p>
      </div>
    </template>
  </div>
</div>
