const help = {
    email: 'Introduce una dirección de correo válida. Se usará para las comunicaciones relacionadas con este registro.',
    username: 'Nombre de acceso de la cuenta; también puedes usar el correo registrado en el acceso.',
    password: 'Introduce una contraseña. Distingue mayúsculas y minúsculas y evita compartirla.',
    amount: 'Importe de esta operación. Usa la moneda y la precisión indicadas en la sección.',
    exchange: 'Tipo de cambio usado para convertir los importes de esta operación.',
    date: 'Selecciona la fecha que corresponde al registro. Revisa el período antes de guardar.',
    file: 'Selecciona el archivo que respalda esta operación. Respeta los formatos y el tamaño indicados.',
    status: 'Estado actual del registro. Cambiarlo puede afectar su disponibilidad para las siguientes acciones.',
    role: 'Define qué acciones y módulos puede utilizar esta cuenta.',
    permission: 'Habilita una acción concreta dentro del sistema. Asigna solo el acceso requerido.',
    project: 'Proyecto al que pertenece el registro. Esta relación determina dónde se consultará su información.',
    chapter: 'Capítulo que organiza este registro y sus relaciones.',
    trip: 'Fase de viaje a la que pertenece esta operación financiera.',
    currency: 'Moneda del importe. Confirma el tipo de cambio si la operación requiere conversión.',
    name: 'Nombre con el que identificarás este registro en las listas y búsquedas.',
    description: 'Describe el concepto con suficiente detalle para que otra persona pueda entender la operación.',
};
export function fieldHelp(id = '', label = '') {
    const key = String(id).toLowerCase();
    for (const [token, message] of Object.entries(help)) if (key.includes(token)) return message;
    return `Completa ${String(label).toLowerCase()} según los datos de este registro. Revisa el valor antes de guardar; los campos obligatorios se validan al enviar.`;
}
