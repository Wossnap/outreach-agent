@props(['disabled' => false])

<input @disabled($disabled) {{ $attributes->merge(['class' => 'border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200 focus:border-indigo-500 dark:focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm']) }}>
