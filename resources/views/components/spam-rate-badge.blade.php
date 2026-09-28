{{-- Gmail's spam-rate thresholds: under 0.1% is fine, 0.3% is where Gmail
     itself starts penalising. A fraction in, a percentage out. --}}
@props(['rate'])
<x-pill :tone="$rate < 0.001 ? 'good' : ($rate < 0.003 ? 'warn' : 'danger')">{{ number_format($rate * 100, 2) }}%</x-pill>
