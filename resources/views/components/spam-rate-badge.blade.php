{{-- Gmail's spam-rate thresholds: under 0.1% is fine, 0.3% is where Gmail
     itself starts penalising. A fraction in, a percentage out. --}}
@props(['rate'])
<span @class([
    'px-2 py-1 rounded text-xs font-semibold whitespace-nowrap',
    'bg-green-100 text-green-800' => $rate < 0.001,
    'bg-yellow-100 text-yellow-800' => $rate >= 0.001 && $rate < 0.003,
    'bg-red-100 text-red-800' => $rate >= 0.003,
])>{{ number_format($rate * 100, 2) }}%</span>
