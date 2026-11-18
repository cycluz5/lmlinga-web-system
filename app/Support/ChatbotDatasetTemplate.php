<?php

namespace App\Support;

/**
 * Starter .txt files an Admin can download before writing a dataset. The headings are the ones the chatbot's
 * section search already understands (taken from the verified corpus in resources/health_docs_v2), so a dataset
 * written like this is found for questions about its topic, signs, treatment, prevention and so on.
 * Bikol-Iriga wording is only what the team's own verified documents already use; the Admin writes the text itself.
 */
final class ChatbotDatasetTemplate
{
    /** @return list<string> the instructions shown above the upload form, in the Admin's language of use */
    public static function instructions(): array
    {
        return [
            'Save the dataset as a plain text file (.txt, UTF-8, up to the size shown). Word or PDF files are not accepted.',
            'Write one topic per file, in one language only. To cover another language, add a translation from the dataset page.',
            'First line: the topic name, short (for example "Tetanus" or "Kanser sa Suso"). The chatbot finds the document by this name, so use the words residents say.',
            'English and Tagalog: an optional second line "MAIN TOPIC: " / "PANGUNAHING PAKSA: " followed by other names for the topic, separated by commas, helps the chatbot find the document.',
            'Next, write what the topic is in one short paragraph. A heading such as "What is ...?" / "Ano ang ...?" / "Ono ana ...?" is optional.',
            'Put each kind of information under its own short heading on its own line: signs, causes, treatment, prevention, benefits, side effects. Use list lines that start with "- " for points.',
            'Use only verified health information. Do not include names, phone numbers, addresses, or any resident or patient information.',
        ];
    }

    public static function filename(string $language): string
    {
        return 'dataset_template_'.$language.'.txt';
    }

    public static function content(string $language): string
    {
        return match ($language) {
            'tl' => self::tagalog(),
            'bcl' => self::bikol(),
            default => self::english(),
        };
    }

    private static function english(): string
    {
        return <<<'TXT'
Topic name (for example: Tetanus)
MAIN TOPIC: Topic name, other name, common short name

What is [topic name]?
Write one short paragraph that says what it is. Keep sentences short and clear.

Causes
- Write each cause on its own line that starts with "- ".

Signs and Symptoms
- Write each sign or symptom on its own line.

Treatment
- Write each treatment step on its own line.

Prevention
- Write each way to prevent it on its own line.

Benefits
- Use this heading only when the topic has benefits (for example vaccines).

Common side effects
- Use this heading only when the topic has side effects.

When to see a health worker
- Write the warning signs that need a health center or hospital.
TXT;
    }

    private static function tagalog(): string
    {
        return <<<'TXT'
Pangalan ng paksa (halimbawa: Tetanus)
PANGUNAHING PAKSA: Pangalan ng paksa, ibang tawag, karaniwang maikling tawag

Ano ang [pangalan ng paksa]?
Sumulat ng isang maikling talata kung ano ito. Gawing maikli at malinaw ang mga pangungusap.

Sanhi
- Isulat ang bawat sanhi sa sariling linya na nagsisimula sa "- ".

Mga Palatandaan
- Isulat ang bawat palatandaan o sintomas sa sariling linya.

Paggamot
- Isulat ang bawat hakbang ng paggamot sa sariling linya.

Pag-iwas
- Isulat ang bawat paraan ng pag-iwas sa sariling linya.

Benepisyo
- Gamitin lamang ang pamagat na ito kung may benepisyo ang paksa (halimbawa: bakuna).

Karaniwang epekto
- Gamitin lamang ang pamagat na ito kung may epekto o side effects ang paksa.

Kailan magpapakonsulta
- Isulat ang mga babalang senyales na kailangang dalhin sa health center o ospital.
TXT;
    }

    private static function bikol(): string
    {
        // Only headings already used by the team's verified Bikol-Iriga documents; everything else is an English
        // instruction in [brackets] for the Admin to replace with the verified Bikol-Iriga text.
        return <<<'TXT'
[Topic name in Bikol-Iriga, for example: Cholera]

Ono ana [topic name]?
[One short paragraph in verified Bikol-Iriga saying what it is.]

Mga Sinyales
- [One sign per line, starting with "- ".]

Madaliang Pagbulong
- [One treatment step per line.]

Pag iwas
- [One way to prevent it per line.]

[Delete every line in square brackets before uploading. Use only verified Bikol-Iriga wording.]
TXT;
    }
}
