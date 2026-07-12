<?php

namespace App\Services;

use App\Models\DocumentTemplate;
use PhpOffice\PhpWord\Element\Table;
use PhpOffice\PhpWord\TemplateProcessor;

/**
 * Merges a generated_documents request against its document_templates .docx
 * and document_template_fields mapping, and saves the filled copy to
 * storage. This is the whole "letter engine": to add a template for a new
 * client, add a document_templates row + document_template_fields rows and
 * drop the .docx in storage/document_templates/ -- nothing here changes.
 */
class DocumentGenerationService
{
    /**
     * @return string Storage-relative path to the generated .docx
     * @throws \RuntimeException if the template file or its field mapping is missing
     */
    public static function generate(array $document): string
    {
        $templates = new DocumentTemplate();
        $template = $templates->find((int) $document['template_id']);

        if (!$template) {
            throw new \RuntimeException('No template is linked to this request.');
        }

        $fields = $templates->fields((int) $template['id']);
        if (empty($fields)) {
            throw new \RuntimeException('This template has no field mapping configured yet -- use "Upload Prepared Letter" instead, or configure its fields under Settings.');
        }

        $templatePath = STORAGE_PATH . '/' . $template['file_path'];
        if (!is_file($templatePath)) {
            throw new \RuntimeException('Template file is missing from storage: ' . $template['file_path']);
        }

        $resolved = DocumentFieldResolver::resolve($document, $fields);

        $processor = new TemplateProcessor($templatePath);

        foreach ($resolved['values'] as $key => $value) {
            $processor->setValue($key, htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'));
        }

        if ($resolved['transactionEvents'] !== null) {
            $processor->setComplexBlock('TRANSACTION_TABLE', self::buildTransactionTable($resolved['transactionEvents']));
        }

        $targetDir = STORAGE_PATH . '/generated_documents';
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        $filename = preg_replace('/[^A-Za-z0-9_-]/', '_', $document['document_no']) . '_' . date('YmdHis') . '.docx';
        $processor->saveAs($targetDir . '/' . $filename);

        return 'generated_documents/' . $filename;
    }

    private static function buildTransactionTable(array $events): Table
    {
        $table = new Table([
            'borderSize' => 6,
            'borderColor' => '000000',
            'cellMargin' => 80,
        ]);

        $table->addRow();
        foreach (['Date', 'Type', 'Description', 'Charged', 'Paid', 'Balance'] as $header) {
            $table->addCell(2000)->addText($header, ['bold' => true]);
        }

        foreach ($events as $event) {
            $table->addRow();
            $table->addCell(2000)->addText($event['date'] ?: '-');
            $table->addCell(2000)->addText($event['type']);
            $table->addCell(2000)->addText($event['description']);
            $table->addCell(2000)->addText($event['debit'] > 0 ? 'N$ ' . format_money($event['debit']) : '');
            $table->addCell(2000)->addText($event['credit'] > 0 ? 'N$ ' . format_money($event['credit']) : '');
            $table->addCell(2000)->addText('N$ ' . format_money($event['balance']));
        }

        return $table;
    }
}
