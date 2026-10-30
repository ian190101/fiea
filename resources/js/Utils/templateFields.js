export const templateFieldLabels = {
    '{{invoice_code}}': 'Código del documento', '{{invoice_type}}': 'Tipo de documento',
    '{{stage}}': 'Etapa del documento', '{{project_code}}': 'Código del proyecto',
    '{{project_name}}': 'Nombre del proyecto', '{{team_name}}': 'Nombre del equipo',
    '{{grand_total}}': 'Importe total en USD', '{{total_dr}}': 'Total DR en USD', '{{total_wodr}}': 'Total WODR en USD',
};

export function readableTemplate(value = '') {
    return String(value).replace(/\{\{\s*([a-z_]+)\s*\}\}/g, (match, key) => {
        const label = templateFieldLabels[`{{${key}}}`];
        return label ? `‹${label}›` : match;
    });
}

export function storedTemplate(value = '') {
    let result = String(value);
    for (const [token, label] of Object.entries(templateFieldLabels)) result = result.replaceAll(`‹${label}›`, token);
    return result;
}

export function sampleTemplate(value = '', { invoiceType = 'IC', stage = 'initial' } = {}) {
    const examples = { '{{invoice_code}}': 'FIEA-2026-001', '{{invoice_type}}': invoiceType, '{{stage}}': stage === 'final' ? 'Final' : 'Initial', '{{project_code}}': 'PROY-001', '{{project_name}}': 'Community Project', '{{team_name}}': 'Project Team', '{{grand_total}}': '$1,250.00', '{{total_dr}}': '$1,000.00', '{{total_wodr}}': '$250.00' };
    return String(value).replace(/\{\{\s*([a-z_]+)\s*\}\}/g, (match, key) => examples[`{{${key}}}`] ?? match);
}
