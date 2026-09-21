<?php

namespace App\Http\Requests;

use App\Models\Contact;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Accepts one contact or a batch, and normalises both into a list so the
 * controller only ever deals with one shape.
 */
class PushContactsRequest extends FormRequest
{
    /** Fields that describe one person, in both the single and batch shapes. */
    private const FIELDS = [
        'email', 'profile_url', 'name', 'first_name', 'last_name',
        'company', 'domain', 'job_title', 'category', 'niche', 'company_url',
        'source', 'extra', 'tags',
    ];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $perContact = [
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'profile_url' => ['nullable', 'url', 'max:255'],
            'name' => ['nullable', 'string', 'max:255'],
            'first_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'company' => ['nullable', 'string', 'max:255'],
            // A full URL is accepted: it is reduced to its host, so a caller
            // holding a website and one holding a bare domain send the same
            // field rather than needing one each.
            'domain' => ['nullable', 'string', 'max:2048'],
            'job_title' => ['nullable', 'string', 'max:1000'],
            // The lead's market, as the source classifies it. Columns rather
            // than entries in `extra` so the Leads page can filter and sort by
            // them.
            'category' => ['nullable', 'string', 'max:255'],
            'niche' => ['nullable', 'string', 'max:255'],
            // The company's LinkedIn page; `profile_url` is the person's.
            'company_url' => ['nullable', 'url', 'max:255'],
            'source' => ['nullable', 'string', 'max:255'],
            'extra' => ['nullable', 'array'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:255'],
        ];

        $rules = ['contacts' => ['sometimes', 'array', 'min:1', 'max:500']];

        // Applied to both shapes at once. "contacts.*.email" covers a batch and
        // "email" a single contact, and only the keys actually present are
        // checked, so one set of rules serves both.
        foreach ($perContact as $field => $rule) {
            $rules["contacts.*.{$field}"] = $rule;
            $rules[$field] = $rule;
        }

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->has('contacts') && ! $this->hasAny(self::FIELDS)) {
                $validator->errors()->add('contacts', 'Send either a single contact, or a "contacts" array of them.');

                return;
            }

            /*
             * A contact has to be identifiable by something, or there is no way
             * to tell them apart from anyone else. The database cannot enforce
             * this: every one of these columns is legitimately empty on its
             * own, just never all at once.
             *
             * A name at a company domain counts, and has to. Finding an address
             * for somebody is half of what this system exists to do, and a
             * source that already had the address would have no reason to ask.
             * Requiring an email would make the entire find step unreachable.
             */
            foreach ($this->contacts() as $index => $contact) {
                $hasContact = filled($contact['email'] ?? null) || filled($contact['profile_url'] ?? null);
                $hasPerson = filled($contact['name'] ?? null)
                    && filled(Contact::domainFrom($contact['domain'] ?? null));

                if (! $hasContact && ! $hasPerson) {
                    $validator->errors()->add(
                        $this->has('contacts') ? "contacts.{$index}" : 'email',
                        'A contact needs an email, or a profile_url, or a name together with a domain.',
                    );
                }
            }
        });
    }

    /**
     * The submitted contacts, as a list whether one was sent or many.
     *
     * @return array<int, array<string, mixed>>
     */
    public function contacts(): array
    {
        $contacts = $this->has('contacts')
            ? $this->input('contacts')
            : [$this->only(self::FIELDS)];

        return array_values(array_filter($contacts, 'is_array'));
    }
}
