<?php

namespace App\Modules\RRHH\Services;

use App\Modules\Shared\Database\RawConnection;

class DocumentoService
{
    private const EMPRESA = 'GAMBARTE BOLIVIA S.R.L.';
    private const NIT = '1024717022';
    private const GERENTE = 'Edelmira Ramos Mollo';
    private const DIRECCION = 'Calle Mercado N° 1335, PB, Oficina 102';
    private const TELEFONO = '+591 2200020';
    private const CELULAR = '+591 68355517';
    private const CORREO = 'info@gambarte.com.bo';

    public function listarPlantillas(): array
    {
        return [
            ['id' => 'contrato_indefinido', 'nombre' => 'Contrato de Trabajo a Tiempo Indefinido', 'categoria' => 'CONTRATO', 'descripcion' => 'Contrato laboral sin plazo determinado conforme Art. 12 LGT y DS 28699', 'norma' => 'Art. 12 LGT, DS 28699', 'campos' => ['id_empleado']],
            ['id' => 'contrato_plazo_fijo', 'nombre' => 'Contrato de Trabajo a Plazo Fijo', 'categoria' => 'CONTRATO', 'descripcion' => 'Contrato con plazo determinado, máximo 1 año renovable una vez (DS 16187)', 'norma' => 'Art. 12 LGT, DS 16187', 'campos' => ['id_empleado', 'fecha_fin']],
            ['id' => 'contrato_teletrabajo', 'nombre' => 'Contrato de Teletrabajo', 'categoria' => 'CONTRATO', 'descripcion' => 'Contrato para modalidad de trabajo a distancia conforme DS 4218', 'norma' => 'DS 4218, Art. 12 LGT', 'campos' => ['id_empleado']],
            ['id' => 'anexo_cambio_cargo', 'nombre' => 'Anexo — Cambio de Cargo / Promoción', 'categoria' => 'ANEXO', 'descripcion' => 'Modificación de cargo y/o funciones del empleado', 'norma' => 'Art. 7 DR-LGT, Art. 113° RI', 'campos' => ['id_empleado', 'nuevo_cargo', 'nuevo_sueldo', 'fecha_efectiva']],
            ['id' => 'anexo_cambio_sueldo', 'nombre' => 'Anexo — Incremento Salarial', 'categoria' => 'ANEXO', 'descripcion' => 'Modificación de remuneración del empleado', 'norma' => 'DS anual de incremento salarial', 'campos' => ['id_empleado', 'nuevo_sueldo', 'fecha_efectiva']],
            ['id' => 'anexo_cambio_agencia', 'nombre' => 'Anexo — Transferencia de Agencia', 'categoria' => 'ANEXO', 'descripcion' => 'Cambio del lugar de trabajo del empleado', 'norma' => 'Art. 7 DR-LGT', 'campos' => ['id_empleado', 'nueva_agencia', 'fecha_efectiva']],
            ['id' => 'memo_designacion', 'nombre' => 'Memorándum — Designación de Cargo', 'categoria' => 'MEMORANDUM', 'descripcion' => 'Designación formal o promoción a un nuevo cargo', 'norma' => 'Art. 113° y 114° RI, Art. 65 LGT', 'campos' => ['id_empleado', 'nuevo_cargo', 'nueva_agencia', 'numero_memo', 'fecha']],
            ['id' => 'memo_llamada_atencion', 'nombre' => 'Memorándum — Llamada de Atención', 'categoria' => 'MEMORANDUM', 'descripcion' => 'Amonestación escrita al empleado por incumplimiento', 'norma' => 'Art. 16 LGT, Art. 9 DR-LGT', 'campos' => ['id_empleado', 'motivo', 'detalle', 'numero_memo', 'fecha']],
            ['id' => 'memo_felicitacion', 'nombre' => 'Memorándum — Felicitación', 'categoria' => 'MEMORANDUM', 'descripcion' => 'Reconocimiento por desempeño destacado', 'norma' => '', 'campos' => ['id_empleado', 'motivo', 'detalle', 'numero_memo', 'fecha']],
            ['id' => 'memo_comunicado', 'nombre' => 'Memorándum — Comunicado Interno', 'categoria' => 'MEMORANDUM', 'descripcion' => 'Comunicación interna general o específica', 'norma' => '', 'campos' => ['destinatario', 'asunto', 'contenido', 'numero_memo', 'fecha']],
            ['id' => 'certificado_trabajo', 'nombre' => 'Certificado de Trabajo', 'categoria' => 'CERTIFICADO', 'descripcion' => 'Certificación de relación laboral vigente o pasada', 'norma' => 'Art. 16 DR-LGT', 'campos' => ['id_empleado']],
            ['id' => 'carta_referencia', 'nombre' => 'Carta de Referencia Laboral', 'categoria' => 'CERTIFICADO', 'descripcion' => 'Referencia laboral del empleado', 'norma' => '', 'campos' => ['id_empleado']],
        ];
    }

    public function generar(string $plantilla, array $params): array
    {
        $empleado = null;
        $idEmpleado = (int)($params['id_empleado'] ?? 0);

        if ($idEmpleado > 0) {
            $empleado = $this->buscarEmpleadoReal($idEmpleado);
        }

        $fecha = $params['fecha'] ?? date('Y-m-d');
        $hoy = $this->fechaTexto($fecha);

        $methods = [
            'contrato_indefinido' => 'contratoIndefinido',
            'contrato_plazo_fijo' => 'contratoPlazoFijo',
            'contrato_teletrabajo' => 'contratoTeletrabajo',
            'anexo_cambio_cargo' => 'anexoCambioCargo',
            'anexo_cambio_sueldo' => 'anexoCambioSueldo',
            'anexo_cambio_agencia' => 'anexoCambioAgencia',
            'memo_designacion' => 'memoDesignacion',
            'memo_llamada_atencion' => 'memoLlamadaAtencion',
            'memo_felicitacion' => 'memoFelicitacion',
            'memo_comunicado' => 'memoComunicado',
            'certificado_trabajo' => 'certificadoTrabajo',
            'carta_referencia' => 'cartaReferencia',
        ];

        if (!isset($methods[$plantilla])) {
            throw new \InvalidArgumentException("Plantilla no encontrada: {$plantilla}");
        }

        $method = $methods[$plantilla];
        return $this->$method($empleado, $hoy, $params);
    }

    public function listarGenerados(): array
    {
        return [
            ['id' => 1, 'plantilla' => 'contrato_indefinido', 'nombre' => 'Contrato — Gutiérrez Fernández Carlos Alberto', 'empleado' => 'Gutiérrez Fernández Carlos Alberto', 'fecha' => '2015-03-01', 'categoria' => 'CONTRATO'],
            ['id' => 2, 'plantilla' => 'contrato_plazo_fijo', 'nombre' => 'Contrato — Flores Condori María Elena', 'empleado' => 'Flores Condori María Elena', 'fecha' => '2024-06-01', 'categoria' => 'CONTRATO'],
            ['id' => 3, 'plantilla' => 'memo_designacion', 'nombre' => 'Memo GMB/GG/ME-096/2025 — Arispe Dennis', 'empleado' => 'Arispe Dennis', 'fecha' => '2025-12-03', 'categoria' => 'MEMORANDUM'],
            ['id' => 4, 'plantilla' => 'memo_designacion', 'nombre' => 'Memo GMB/GG/ME-083/2025 — Quisbert Nacho Gabriel', 'empleado' => 'Quisbert Nacho Gabriel', 'fecha' => '2025-09-22', 'categoria' => 'MEMORANDUM'],
            ['id' => 5, 'plantilla' => 'certificado_trabajo', 'nombre' => 'Certificado — Torrez Vargas Ana Lucía', 'empleado' => 'Torrez Vargas Ana Lucía', 'fecha' => '2026-08-20', 'categoria' => 'CERTIFICADO'],
        ];
    }

    private function buscarEmpleadoReal(int $id): ?array
    {
        return RawConnection::selectOne(
            "SELECT e.id, e.ci, e.ci_expedido, e.nombres, e.apellido_paterno, e.apellido_materno,
                    e.genero, e.estado, e.telefono, e.email_personal,
                    e.cargo, e.area, e.agencia,
                    c.id AS id_contrato, c.tipo_contrato, c.fecha_inicio, c.fecha_fin,
                    c.sueldo_base, c.estado AS estado_contrato
             FROM rrhh_empleado e
             LEFT JOIN rrhh_contrato c ON c.id_empleado = e.id AND c.estado = 'VIGENTE'
             WHERE e.id = :id
             LIMIT 1",
            [':id' => $id]
        );
    }

    // ── Helpers ──

    private function nombreCompleto(array $e): string
    {
        return trim($e['apellido_paterno'] . ' ' . ($e['apellido_materno'] ?? '') . ' ' . $e['nombres']);
    }

    private function fechaTexto(string $fecha): string
    {
        $meses = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
        $parts = explode('-', $fecha);
        $d = (int)$parts[2];
        $m = $meses[(int)$parts[1] - 1];
        $y = $parts[0];
        return "{$d} de {$m} de {$y}";
    }

    private function sueldoTexto(float $sueldo): string
    {
        return number_format($sueldo, 2, ',', '.') . ' Bolivianos';
    }

    private function memoNumero(array $params): string
    {
        $num = $params['numero_memo'] ?? '001';
        $year = date('Y', strtotime($params['fecha'] ?? 'now'));
        return "GMB/GG/ME-{$num}/{$year}";
    }

    private function result(string $plantilla, string $titulo, string $empleado, string $fecha, string $contenido, string $norma): array
    {
        return compact('plantilla', 'titulo', 'empleado', 'fecha', 'contenido', 'norma');
    }

    // ── Contratos ──

    private function contratoIndefinido(?array $emp, string $hoy, array $params): array
    {
        if (!$emp) throw new \InvalidArgumentException('Empleado no encontrado');
        $n = $this->nombreCompleto($emp);
        $ci = $emp['ci'] . ' ' . ($emp['ci_expedido'] ?? 'LP');
        $cargo = $emp['cargo'] ?? 'Sin cargo';
        $area = $emp['area'] ?? '';
        $sueldo = $this->sueldoTexto((float)($emp['sueldo_base'] ?? 0));
        $fi = $this->fechaTexto($emp['fecha_inicio'] ?? date('Y-m-d'));
        $ag = $emp['agencia'] ?? 'Oficina Central';
        $E = self::EMPRESA;
        $G = self::GERENTE;

        $c = <<<HTML
<h2>CONTRATO INDIVIDUAL DE TRABAJO A TIEMPO INDEFINIDO</h2>

<p>Conste por el presente documento privado, un <strong>CONTRATO INDIVIDUAL DE TRABAJO A TIEMPO INDEFINIDO</strong>, suscrito al tenor de las siguientes cláusulas:</p>

<h3>PRIMERA. (DE LAS PARTES)</h3>
<p><strong>1.1. EL EMPLEADOR:</strong> {$E}, con NIT 1024717022, representada legalmente por su Gerente General, Sra. {$G}, con domicilio legal en la ciudad de La Paz, Bolivia, entidad regulada por la Autoridad de Supervisión del Sistema Financiero (ASFI), que en adelante se denominará "LA EMPRESA".</p>
<p><strong>1.2. EL TRABAJADOR:</strong> {$n}, con Cédula de Identidad Nro. {$ci}, mayor de edad, hábil por derecho, con domicilio en la ciudad de La Paz, que en adelante se denominará "EL TRABAJADOR".</p>

<h3>SEGUNDA. (DEL OBJETO)</h3>
<p>Por el presente contrato, LA EMPRESA contrata los servicios de EL TRABAJADOR para desempeñar el cargo de <strong>{$cargo}</strong> en el área de <strong>{$area}</strong>, en la agencia/oficina de <strong>{$ag}</strong>, debiendo cumplir con las funciones inherentes al cargo de acuerdo al Manual de Funciones vigente.</p>

<h3>TERCERA. (DE LA DURACIÓN)</h3>
<p>El presente contrato es de duración <strong>INDEFINIDA</strong>, conforme establece el Art. 12 de la Ley General del Trabajo (LGT) y el Decreto Supremo 28699 de 1 de mayo de 2006, que garantiza la estabilidad laboral del trabajador. La relación laboral tiene como fecha de inicio el <strong>{$fi}</strong>.</p>

<h3>CUARTA. (DE LA REMUNERACIÓN)</h3>
<p>LA EMPRESA pagará a EL TRABAJADOR un salario mensual de <strong>Bs. {$sueldo}</strong> (monto bruto), sujeto a las deducciones de ley (AFP, RC-IVA, y otros establecidos por normativa vigente). El pago se realizará de forma mensual conforme al Art. 52 de la LGT.</p>

<h3>QUINTA. (DE LA JORNADA LABORAL)</h3>
<p>La jornada laboral será de <strong>8 horas diarias</strong> y <strong>48 horas semanales</strong> para personal masculino, o <strong>7 horas diarias</strong> y <strong>40 horas semanales</strong> para personal femenino, conforme al Art. 46 de la LGT. Las horas extraordinarias serán remuneradas con un recargo del 100% conforme al Art. 50 de la LGT.</p>

<h3>SEXTA. (DE LAS OBLIGACIONES DEL TRABAJADOR)</h3>
<p>EL TRABAJADOR se obliga a:</p>
<ol type="a">
<li>Cumplir las funciones asignadas con diligencia, eficiencia y responsabilidad.</li>
<li>Cumplir los reglamentos internos, manuales de funciones y políticas de LA EMPRESA.</li>
<li>Guardar absoluta reserva y confidencialidad sobre las operaciones, clientes e información de LA EMPRESA, conforme a la normativa ASFI y la Ley 004 (Marcelo Quiroga Santa Cruz).</li>
<li>Informar de inmediato sobre operaciones inusuales o sospechosas conforme la normativa de Prevención de Legitimación de Ganancias Ilícitas.</li>
<li>Asistir a las capacitaciones obligatorias establecidas por ASFI.</li>
</ol>

<h3>SÉPTIMA. (DE LAS OBLIGACIONES DEL EMPLEADOR)</h3>
<p>LA EMPRESA se obliga a:</p>
<ol type="a">
<li>Pagar puntualmente la remuneración pactada.</li>
<li>Afiliar a EL TRABAJADOR a la Caja Nacional de Salud (CNS) y a la Administradora de Fondos de Pensiones (AFP) correspondiente.</li>
<li>Otorgar vacaciones conforme a la escala establecida por el DS 12058.</li>
<li>Cumplir con el pago de aguinaldo, bonos y demás beneficios sociales conforme a ley.</li>
<li>Proporcionar las herramientas y condiciones necesarias para el desempeño de las funciones.</li>
</ol>

<h3>OCTAVA. (DE LAS CAUSALES DE TERMINACIÓN)</h3>
<p>El presente contrato podrá ser resuelto por las causales establecidas en los Arts. 16 y 9 de la LGT y su Decreto Reglamentario, respectivamente. En caso de despido injustificado, EL TRABAJADOR tendrá derecho a la indemnización y desahucio conforme a ley.</p>

<h3>NOVENA. (CONFIDENCIALIDAD Y NORMATIVA ASFI)</h3>
<p>EL TRABAJADOR reconoce que LA EMPRESA opera bajo la regulación de ASFI y se compromete a cumplir toda la normativa aplicable, incluyendo pero no limitándose a: secreto bancario (Art. 86 de la Ley de Bancos y Entidades Financieras), prevención de legitimación de ganancias ilícitas, y protección del consumidor financiero.</p>

<h3>DÉCIMA. (CONFORMIDAD)</h3>
<p>En señal de conformidad y aceptación de todas las cláusulas precedentes, firman ambas partes en la ciudad de La Paz, a los {$hoy}.</p>

<div class="firmas">
<div class="firma-bloque">
<div class="firma-linea"></div>
<p><strong>{$G}</strong></p>
<p>GERENTE GENERAL</p>
<p>{$E}</p>
</div>
<div class="firma-bloque">
<div class="firma-linea"></div>
<p><strong>EL TRABAJADOR</strong></p>
<p>{$n}</p>
<p>C.I. {$ci}</p>
</div>
</div>
HTML;

        return $this->result('contrato_indefinido', 'CONTRATO INDIVIDUAL DE TRABAJO A TIEMPO INDEFINIDO', $n, $params['fecha'] ?? date('Y-m-d'), $c, 'Art. 12 LGT, DS 28699');
    }

    private function contratoPlazoFijo(?array $emp, string $hoy, array $params): array
    {
        if (!$emp) throw new \InvalidArgumentException('Empleado no encontrado');
        $n = $this->nombreCompleto($emp);
        $ci = $emp['ci'] . ' ' . ($emp['ci_expedido'] ?? 'LP');
        $cargo = $emp['cargo'] ?? 'Sin cargo';
        $area = $emp['area'] ?? '';
        $sueldo = $this->sueldoTexto((float)($emp['sueldo_base'] ?? 0));
        $fi = $this->fechaTexto($emp['fecha_inicio'] ?? date('Y-m-d'));
        $ag = $emp['agencia'] ?? 'Oficina Central';
        $ff = $this->fechaTexto($params['fecha_fin'] ?? date('Y-m-d', strtotime('+1 year')));
        $E = self::EMPRESA; $G = self::GERENTE;

        $c = <<<HTML
<h2>CONTRATO INDIVIDUAL DE TRABAJO A PLAZO FIJO</h2>

<p>Conste por el presente documento privado, un <strong>CONTRATO INDIVIDUAL DE TRABAJO A PLAZO FIJO</strong>, suscrito al tenor de las siguientes cláusulas:</p>

<h3>PRIMERA. (DE LAS PARTES)</h3>
<p><strong>1.1. EL EMPLEADOR:</strong> {$E}, con NIT 1024717022, representada legalmente por su Gerente General, Sra. {$G}, entidad regulada por ASFI.</p>
<p><strong>1.2. EL TRABAJADOR:</strong> {$n}, con Cédula de Identidad Nro. {$ci}, mayor de edad, hábil por derecho.</p>

<h3>SEGUNDA. (DEL OBJETO)</h3>
<p>LA EMPRESA contrata los servicios de EL TRABAJADOR para desempeñar el cargo de <strong>{$cargo}</strong> en el área de <strong>{$area}</strong>, en la agencia/oficina de <strong>{$ag}</strong>.</p>

<h3>TERCERA. (DE LA DURACIÓN)</h3>
<p>El presente contrato es de <strong>PLAZO FIJO</strong>, conforme al Art. 12 de la LGT. La vigencia inicia el <strong>{$fi}</strong> y finaliza el <strong>{$ff}</strong>.</p>
<p>De conformidad con el DS 16187, este contrato podrá ser renovado por una sola vez. La segunda renovación o la continuidad del trabajador más allá de la duración pactada convierte automáticamente la relación en contrato a tiempo indefinido, conforme al Art. 21 de la LGT y el DS 28699.</p>

<h3>CUARTA. (DE LA REMUNERACIÓN)</h3>
<p>LA EMPRESA pagará a EL TRABAJADOR un salario mensual de <strong>Bs. {$sueldo}</strong> (monto bruto), sujeto a las deducciones de ley.</p>

<h3>QUINTA. (DE LA JORNADA LABORAL)</h3>
<p>La jornada laboral será conforme al Art. 46 de la LGT: 8 horas diarias (48h/semana) para personal masculino, 7 horas diarias (40h/semana) para personal femenino.</p>

<h3>SEXTA. (DE LAS OBLIGACIONES)</h3>
<p>Ambas partes se obligan al cumplimiento de las disposiciones contenidas en la Ley General del Trabajo, su Decreto Reglamentario, y toda la normativa ASFI aplicable a casas de cambio, incluyendo las obligaciones de confidencialidad y prevención de legitimación de ganancias ilícitas.</p>

<h3>SÉPTIMA. (DE LA TERMINACIÓN)</h3>
<p>El contrato terminará por vencimiento del plazo pactado, sin necesidad de preaviso ni desahucio. En caso de resolución anticipada sin causa justificada por parte del empleador, EL TRABAJADOR tendrá derecho a reclamar daños y perjuicios equivalentes al sueldo por el tiempo que reste del contrato.</p>

<h3>OCTAVA. (CONFORMIDAD)</h3>
<p>Firman ambas partes en la ciudad de La Paz, a los {$hoy}.</p>

<div class="firmas">
<div class="firma-bloque"><div class="firma-linea"></div><p><strong>{$G}</strong></p><p>GERENTE GENERAL</p><p>{$E}</p></div>
<div class="firma-bloque"><div class="firma-linea"></div><p><strong>EL TRABAJADOR</strong></p><p>{$n}</p><p>C.I. {$ci}</p></div>
</div>
HTML;

        return $this->result('contrato_plazo_fijo', 'CONTRATO INDIVIDUAL DE TRABAJO A PLAZO FIJO', $n, $params['fecha'] ?? date('Y-m-d'), $c, 'Art. 12 LGT, DS 16187');
    }

    private function contratoTeletrabajo(?array $emp, string $hoy, array $params): array
    {
        if (!$emp) throw new \InvalidArgumentException('Empleado no encontrado');
        $n = $this->nombreCompleto($emp);
        $ci = $emp['ci'] . ' ' . ($emp['ci_expedido'] ?? 'LP');
        $cargo = $emp['cargo'] ?? 'Sin cargo';
        $area = $emp['area'] ?? '';
        $sueldo = $this->sueldoTexto((float)($emp['sueldo_base'] ?? 0));
        $fi = $this->fechaTexto($emp['fecha_inicio'] ?? date('Y-m-d'));
        $E = self::EMPRESA; $G = self::GERENTE;

        $c = <<<HTML
<h2>CONTRATO DE TRABAJO EN MODALIDAD DE TELETRABAJO</h2>

<p>Conste por el presente documento privado, un <strong>CONTRATO DE TRABAJO EN MODALIDAD DE TELETRABAJO</strong>, suscrito conforme al Decreto Supremo 4218 de 14 de abril de 2020 y la Ley General del Trabajo.</p>

<h3>PRIMERA. (DE LAS PARTES)</h3>
<p><strong>1.1. EL EMPLEADOR:</strong> {$E}, NIT 1024717022, regulada por ASFI, representada por su Gerente General, Sra. {$G}.</p>
<p><strong>1.2. EL TRABAJADOR:</strong> {$n}, C.I. {$ci}.</p>

<h3>SEGUNDA. (DEL OBJETO Y MODALIDAD)</h3>
<p>LA EMPRESA contrata los servicios de EL TRABAJADOR para desempeñar el cargo de <strong>{$cargo}</strong> en el área de <strong>{$area}</strong>, bajo la modalidad de <strong>TELETRABAJO</strong> conforme al DS 4218.</p>
<p>EL TRABAJADOR realizará sus funciones desde su domicilio o lugar habilitado, utilizando tecnologías de información y comunicación proporcionadas o aprobadas por LA EMPRESA.</p>

<h3>TERCERA. (DE LA DURACIÓN)</h3>
<p>El presente contrato es de duración <strong>INDEFINIDA</strong>, iniciando el <strong>{$fi}</strong>. La modalidad de teletrabajo podrá ser revertida a presencial por acuerdo de partes o por necesidad justificada de LA EMPRESA.</p>

<h3>CUARTA. (DE LA REMUNERACIÓN)</h3>
<p>Salario mensual de <strong>Bs. {$sueldo}</strong> (bruto), con las mismas deducciones y beneficios que el trabajo presencial.</p>

<h3>QUINTA. (DE LA JORNADA Y CONTROL)</h3>
<p>La jornada laboral será la establecida por el Art. 46 de la LGT. EL TRABAJADOR deberá estar disponible durante el horario convenido y reportar su actividad según los mecanismos de control establecidos por LA EMPRESA.</p>
<p>Conforme al Art. 5 del DS 4218, el teletrabajador tiene derecho a la <strong>desconexión digital</strong> fuera de la jornada laboral.</p>

<h3>SEXTA. (DE LOS EQUIPOS Y CONECTIVIDAD)</h3>
<p>LA EMPRESA proporcionará o subsidiará los equipos informáticos necesarios. EL TRABAJADOR es responsable de mantener una conexión a internet estable y un espacio de trabajo adecuado.</p>

<h3>SÉPTIMA. (SEGURIDAD DE LA INFORMACIÓN)</h3>
<p>EL TRABAJADOR se compromete a cumplir las políticas de seguridad de la información de LA EMPRESA, incluyendo el uso de VPN, cifrado de datos, y las disposiciones de ASFI sobre seguridad de la información aplicables a casas de cambio. Queda prohibido acceder a los sistemas de LA EMPRESA desde redes públicas no seguras.</p>

<h3>OCTAVA. (CONFORMIDAD)</h3>
<p>Firman ambas partes en la ciudad de La Paz, a los {$hoy}.</p>

<div class="firmas">
<div class="firma-bloque"><div class="firma-linea"></div><p><strong>{$G}</strong></p><p>GERENTE GENERAL</p><p>{$E}</p></div>
<div class="firma-bloque"><div class="firma-linea"></div><p><strong>EL TRABAJADOR</strong></p><p>{$n}</p><p>C.I. {$ci}</p></div>
</div>
HTML;

        return $this->result('contrato_teletrabajo', 'CONTRATO DE TRABAJO EN MODALIDAD DE TELETRABAJO', $n, $params['fecha'] ?? date('Y-m-d'), $c, 'DS 4218, Art. 12 LGT');
    }

    // ── Anexos ──

    private function anexoCambioCargo(?array $emp, string $hoy, array $params): array
    {
        if (!$emp) throw new \InvalidArgumentException('Empleado no encontrado');
        $n = $this->nombreCompleto($emp);
        $ci = $emp['ci'] . ' ' . ($emp['ci_expedido'] ?? 'LP');
        $cargoAnt = $emp['cargo'] ?? 'Sin cargo';
        $nc = $params['nuevo_cargo'] ?? 'Nuevo Cargo';
        $ns = $this->sueldoTexto((float)($params['nuevo_sueldo'] ?? $emp['sueldo_base'] ?? 0));
        $sa = $this->sueldoTexto((float)($emp['sueldo_base'] ?? 0));
        $fe = $this->fechaTexto($params['fecha_efectiva'] ?? date('Y-m-d'));
        $E = self::EMPRESA; $G = self::GERENTE;

        $c = <<<HTML
<h2>ANEXO DE CONTRATO DE TRABAJO</h2>
<h3 class="subtitulo">CAMBIO DE CARGO — PROMOCIÓN</h3>

<p>Conste por el presente documento, un <strong>ANEXO AL CONTRATO INDIVIDUAL DE TRABAJO</strong> suscrito entre las partes, que modifica las condiciones del contrato original:</p>

<h3>PRIMERA. (ANTECEDENTE)</h3>
<p>EL TRABAJADOR <strong>{$n}</strong>, C.I. {$ci}, mantiene relación laboral con {$E} desempeñando actualmente el cargo de <strong>{$cargoAnt}</strong> con un salario de <strong>Bs. {$sa}</strong>.</p>

<h3>SEGUNDA. (MODIFICACIÓN)</h3>
<p>Por decisión de LA EMPRESA y en reconocimiento a sus méritos, aptitudes, eficiencia, esmero, puntualidad y cumplimiento en el trabajo, así como a su experiencia y antigüedad, conforme a los requisitos establecidos en el Artículo 114° del Reglamento Interno de Trabajo, a partir del <strong>{$fe}</strong>, EL TRABAJADOR pasará a desempeñar el cargo de <strong>{$nc}</strong> con una remuneración mensual de <strong>Bs. {$ns}</strong>.</p>
<p>Esta promoción obedece a la política de {$E} de fomentar una línea de carrera para el personal destacado, en observancia del Artículo 113° del mismo Reglamento y el Artículo 65 de la Ley General del Trabajo.</p>

<h3>TERCERA. (CONDICIONES)</h3>
<p>Las demás condiciones del contrato original se mantienen vigentes. La antigüedad del trabajador se computa desde la fecha de inicio de la relación laboral original, conforme al principio de continuidad laboral establecido en el DS 28699.</p>

<h3>CUARTA. (CONFORMIDAD)</h3>
<p>Firman en la ciudad de La Paz, a los {$hoy}.</p>

<div class="firmas">
<div class="firma-bloque"><div class="firma-linea"></div><p><strong>{$G}</strong></p><p>GERENTE GENERAL</p><p>{$E}</p></div>
<div class="firma-bloque"><div class="firma-linea"></div><p><strong>EL TRABAJADOR</strong></p><p>{$n}</p><p>C.I. {$ci}</p></div>
</div>
HTML;

        return $this->result('anexo_cambio_cargo', 'ANEXO DE CONTRATO — CAMBIO DE CARGO', $n, $params['fecha'] ?? date('Y-m-d'), $c, 'Art. 7 DR-LGT, Art. 113° y 114° RI, DS 28699');
    }

    private function anexoCambioSueldo(?array $emp, string $hoy, array $params): array
    {
        if (!$emp) throw new \InvalidArgumentException('Empleado no encontrado');
        $n = $this->nombreCompleto($emp);
        $ci = $emp['ci'] . ' ' . ($emp['ci_expedido'] ?? 'LP');
        $sa = $this->sueldoTexto((float)($emp['sueldo_base'] ?? 0));
        $ns = $this->sueldoTexto((float)($params['nuevo_sueldo'] ?? $emp['sueldo_base'] ?? 0));
        $fe = $this->fechaTexto($params['fecha_efectiva'] ?? date('Y-m-d'));
        $E = self::EMPRESA; $G = self::GERENTE;

        $c = <<<HTML
<h2>ANEXO DE CONTRATO DE TRABAJO</h2>
<h3 class="subtitulo">INCREMENTO SALARIAL</h3>

<p>Conste por el presente documento un <strong>ANEXO</strong> al contrato de trabajo que modifica la remuneración:</p>

<h3>PRIMERA. (ANTECEDENTE)</h3>
<p>EL TRABAJADOR <strong>{$n}</strong>, C.I. {$ci}, percibe actualmente un salario de <strong>Bs. {$sa}</strong>.</p>

<h3>SEGUNDA. (MODIFICACIÓN)</h3>
<p>A partir del <strong>{$fe}</strong>, la remuneración mensual será de <strong>Bs. {$ns}</strong>, conforme al Decreto Supremo anual de incremento salarial vigente.</p>

<h3>TERCERA. (CONDICIONES)</h3>
<p>Las demás condiciones del contrato original permanecen inalteradas.</p>

<h3>CUARTA. (CONFORMIDAD)</h3>
<p>Firman en la ciudad de La Paz, a los {$hoy}.</p>

<div class="firmas">
<div class="firma-bloque"><div class="firma-linea"></div><p><strong>{$G}</strong></p><p>GERENTE GENERAL</p><p>{$E}</p></div>
<div class="firma-bloque"><div class="firma-linea"></div><p><strong>EL TRABAJADOR</strong></p><p>{$n}</p><p>C.I. {$ci}</p></div>
</div>
HTML;

        return $this->result('anexo_cambio_sueldo', 'ANEXO DE CONTRATO — INCREMENTO SALARIAL', $n, $params['fecha'] ?? date('Y-m-d'), $c, 'DS anual de incremento salarial');
    }

    private function anexoCambioAgencia(?array $emp, string $hoy, array $params): array
    {
        if (!$emp) throw new \InvalidArgumentException('Empleado no encontrado');
        $n = $this->nombreCompleto($emp);
        $ci = $emp['ci'] . ' ' . ($emp['ci_expedido'] ?? 'LP');
        $agAnt = $emp['agencia'] ?? 'Oficina Central';
        $na = $params['nueva_agencia'] ?? 'Nueva Agencia';
        $fe = $this->fechaTexto($params['fecha_efectiva'] ?? date('Y-m-d'));
        $E = self::EMPRESA; $G = self::GERENTE;

        $c = <<<HTML
<h2>ANEXO DE CONTRATO DE TRABAJO</h2>
<h3 class="subtitulo">TRANSFERENCIA DE AGENCIA</h3>

<p>Conste por el presente un <strong>ANEXO</strong> al contrato de trabajo:</p>

<h3>PRIMERA. (ANTECEDENTE)</h3>
<p>EL TRABAJADOR <strong>{$n}</strong>, C.I. {$ci}, presta servicios actualmente en la agencia de <strong>{$agAnt}</strong>.</p>

<h3>SEGUNDA. (TRANSFERENCIA)</h3>
<p>A partir del <strong>{$fe}</strong>, EL TRABAJADOR será transferido a la agencia de <strong>{$na}</strong>, manteniendo su mismo cargo, remuneración y demás condiciones laborales.</p>
<p>Esta medida se adopta en cumplimiento de las normas internas y legales vigentes, asegurando la continuidad y eficiencia de las operaciones en la {$na}.</p>

<h3>TERCERA. (CONFORMIDAD)</h3>
<p>Firman en la ciudad de La Paz, a los {$hoy}.</p>

<div class="firmas">
<div class="firma-bloque"><div class="firma-linea"></div><p><strong>{$G}</strong></p><p>GERENTE GENERAL</p><p>{$E}</p></div>
<div class="firma-bloque"><div class="firma-linea"></div><p><strong>EL TRABAJADOR</strong></p><p>{$n}</p><p>C.I. {$ci}</p></div>
</div>
HTML;

        return $this->result('anexo_cambio_agencia', 'ANEXO DE CONTRATO — TRANSFERENCIA DE AGENCIA', $n, $params['fecha'] ?? date('Y-m-d'), $c, 'Art. 7 DR-LGT');
    }

    // ── Memorándums (formato real Gambarte) ──

    private function memoDesignacion(?array $emp, string $hoy, array $params): array
    {
        if (!$emp) throw new \InvalidArgumentException('Empleado no encontrado');
        $n = $this->nombreCompleto($emp);
        $cargoActual = $emp['cargo'] ?? '';
        $nuevoCargo = $params['nuevo_cargo'] ?? 'Nuevo Cargo';
        $nuevaAgencia = $params['nueva_agencia'] ?? ($emp['agencia'] ?? 'Oficina Central');
        $codigo = $this->memoNumero($params);
        $E = self::EMPRESA; $G = self::GERENTE;

        $c = <<<HTML
<h2 class="memo-titulo">MEMORÁNDUM</h2>
<p class="memo-codigo" style="text-align:center">{$codigo}</p>

<div class="memo-datos">
<div class="memo-row"><span class="memo-label">DE:</span><span class="memo-val">{$G}<br><strong>GERENTE GENERAL</strong></span></div>
<div class="memo-row"><span class="memo-label">A:</span><span class="memo-val">{$n}<br><strong>{$cargoActual}</strong></span></div>
<div class="memo-row"><span class="memo-label">REF.:</span><span class="memo-val"><strong>DESIGNACIÓN DE NUEVO CARGO — PROMOCIÓN DE {$cargoActual} A {$nuevoCargo}</strong></span></div>
<div class="memo-row"><span class="memo-label">FECHA:</span><span class="memo-val">{$hoy}</span></div>
</div>
<div class="memo-separator"></div>

<p>Sr. {$emp['apellido_paterno']}:</p>

<p>Se le comunica que a partir de la fecha se le designa como:</p>

<div class="memo-cargo-destino"><strong>{$nuevoCargo} — {$nuevaAgencia}</strong></div>

<p>Efectiva a partir de la fecha de emisión de este memorándum, en reconocimiento a sus méritos, aptitudes, eficiencia, esmero, puntualidad y cumplimiento en el trabajo, así como a su experiencia y antigüedad, conforme a los requisitos establecidos en el Artículo 114° del Reglamento Interno de Trabajo.</p>

<p>Esta promoción obedece a la política de {$E} de fomentar una línea de carrera para el personal destacado, en observancia del Artículo 113° del mismo Reglamento y el Artículo 65 de la Ley General del Trabajo.</p>

<p>Esta medida se adopta en cumplimiento de las normas internas y legales vigentes, asegurando la continuidad y eficiencia de las operaciones en la {$nuevaAgencia}.</p>

<p>Atentamente,</p>

<div class="firmas">
<div class="firma-bloque">
<div class="firma-linea"></div>
<p><strong>{$G}</strong></p>
<p>GERENTE GENERAL</p>
<p>{$E}</p>
</div>
</div>

<div class="memo-pie">
<p>File Personal</p>
<p>C.c. Archivo</p>
</div>
HTML;

        return $this->result('memo_designacion', "MEMORÁNDUM {$codigo} — DESIGNACIÓN DE CARGO", $n, $params['fecha'] ?? date('Y-m-d'), $c, 'Art. 113° y 114° RI, Art. 65 LGT');
    }

    private function memoLlamadaAtencion(?array $emp, string $hoy, array $params): array
    {
        if (!$emp) throw new \InvalidArgumentException('Empleado no encontrado');
        $n = $this->nombreCompleto($emp);
        $cargo = $emp['cargo'] ?? '';
        $motivo = $params['motivo'] ?? 'Incumplimiento de funciones';
        $detalle = $params['detalle'] ?? 'Se ha verificado el incumplimiento de las obligaciones establecidas en el Reglamento Interno de Trabajo.';
        $codigo = $this->memoNumero($params);
        $E = self::EMPRESA; $G = self::GERENTE;

        $c = <<<HTML
<h2 class="memo-titulo">MEMORÁNDUM</h2>
<p class="memo-codigo" style="text-align:center">{$codigo}</p>

<div class="memo-datos">
<div class="memo-row"><span class="memo-label">DE:</span><span class="memo-val">{$G}<br><strong>GERENTE GENERAL</strong></span></div>
<div class="memo-row"><span class="memo-label">A:</span><span class="memo-val">{$n}<br><strong>{$cargo}</strong></span></div>
<div class="memo-row"><span class="memo-label">REF.:</span><span class="memo-val"><strong>LLAMADA DE ATENCIÓN</strong></span></div>
<div class="memo-row"><span class="memo-label">FECHA:</span><span class="memo-val">{$hoy}</span></div>
</div>
<div class="memo-separator"></div>

<p>Sr. {$emp['apellido_paterno']}:</p>

<p>Mediante el presente memorándum, se le hace llegar una <strong>LLAMADA DE ATENCIÓN</strong> por el siguiente motivo:</p>

<p><strong>{$motivo}</strong></p>

<p>{$detalle}</p>

<p>Se le recuerda que, conforme al Art. 16, inciso e) de la Ley General del Trabajo, y al Art. 9 de su Decreto Reglamentario, la reiteración de faltas puede dar lugar a la resolución del contrato de trabajo por causa justificada.</p>

<p>Se le exhorta a corregir su conducta y cumplir estrictamente con sus obligaciones laborales.</p>

<p>Atentamente,</p>

<div class="firmas">
<div class="firma-bloque"><div class="firma-linea"></div><p><strong>{$G}</strong></p><p>GERENTE GENERAL</p><p>{$E}</p></div>
<div class="firma-bloque"><div class="firma-linea"></div><p><strong>Recibido por:</strong></p><p>{$n}</p><p>Fecha: ____/____/____</p></div>
</div>

<div class="memo-pie">
<p>File Personal</p>
<p>C.c. Archivo</p>
</div>
HTML;

        return $this->result('memo_llamada_atencion', "MEMORÁNDUM {$codigo} — LLAMADA DE ATENCIÓN", $n, $params['fecha'] ?? date('Y-m-d'), $c, 'Art. 16 LGT, Art. 9 DR-LGT');
    }

    private function memoFelicitacion(?array $emp, string $hoy, array $params): array
    {
        if (!$emp) throw new \InvalidArgumentException('Empleado no encontrado');
        $n = $this->nombreCompleto($emp);
        $cargo = $emp['cargo'] ?? '';
        $motivo = $params['motivo'] ?? 'Desempeño destacado';
        $detalle = $params['detalle'] ?? 'Se reconoce el excelente desempeño y compromiso demostrado en el ejercicio de sus funciones.';
        $codigo = $this->memoNumero($params);
        $E = self::EMPRESA; $G = self::GERENTE;

        $c = <<<HTML
<h2 class="memo-titulo">MEMORÁNDUM</h2>
<p class="memo-codigo" style="text-align:center">{$codigo}</p>

<div class="memo-datos">
<div class="memo-row"><span class="memo-label">DE:</span><span class="memo-val">{$G}<br><strong>GERENTE GENERAL</strong></span></div>
<div class="memo-row"><span class="memo-label">A:</span><span class="memo-val">{$n}<br><strong>{$cargo}</strong></span></div>
<div class="memo-row"><span class="memo-label">REF.:</span><span class="memo-val"><strong>FELICITACIÓN</strong></span></div>
<div class="memo-row"><span class="memo-label">FECHA:</span><span class="memo-val">{$hoy}</span></div>
</div>
<div class="memo-separator"></div>

<p>Sr. {$emp['apellido_paterno']}:</p>

<p>Mediante el presente memorándum, la Gerencia General expresa su <strong>FELICITACIÓN</strong> por:</p>

<p><strong>{$motivo}</strong></p>

<p>{$detalle}</p>

<p>Este reconocimiento será incluido en su expediente personal como antecedente positivo.</p>

<p>Atentamente,</p>

<div class="firmas">
<div class="firma-bloque"><div class="firma-linea"></div><p><strong>{$G}</strong></p><p>GERENTE GENERAL</p><p>{$E}</p></div>
</div>

<div class="memo-pie">
<p>File Personal</p>
<p>C.c. Archivo</p>
</div>
HTML;

        return $this->result('memo_felicitacion', "MEMORÁNDUM {$codigo} — FELICITACIÓN", $n, $params['fecha'] ?? date('Y-m-d'), $c, '');
    }

    private function memoComunicado(?array $emp, string $hoy, array $params): array
    {
        $dest = $params['destinatario'] ?? 'Todo el personal';
        $asunto = $params['asunto'] ?? 'Comunicado Interno';
        $texto = $params['contenido'] ?? 'Se comunica al personal lo siguiente...';
        $codigo = $this->memoNumero($params);
        $E = self::EMPRESA; $G = self::GERENTE;

        $c = <<<HTML
<h2 class="memo-titulo">MEMORÁNDUM</h2>
<p class="memo-codigo" style="text-align:center">{$codigo}</p>

<div class="memo-datos">
<div class="memo-row"><span class="memo-label">DE:</span><span class="memo-val">{$G}<br><strong>GERENTE GENERAL</strong></span></div>
<div class="memo-row"><span class="memo-label">A:</span><span class="memo-val">{$dest}</span></div>
<div class="memo-row"><span class="memo-label">REF.:</span><span class="memo-val"><strong>{$asunto}</strong></span></div>
<div class="memo-row"><span class="memo-label">FECHA:</span><span class="memo-val">{$hoy}</span></div>
</div>
<div class="memo-separator"></div>

<p>{$texto}</p>

<p>Atentamente,</p>

<div class="firmas">
<div class="firma-bloque"><div class="firma-linea"></div><p><strong>{$G}</strong></p><p>GERENTE GENERAL</p><p>{$E}</p></div>
</div>

<div class="memo-pie">
<p>File Personal</p>
<p>C.c. Archivo</p>
</div>
HTML;

        return $this->result('memo_comunicado', "MEMORÁNDUM {$codigo} — " . strtoupper($asunto), $dest, $params['fecha'] ?? date('Y-m-d'), $c, '');
    }

    // ── Certificados ──

    private function certificadoTrabajo(?array $emp, string $hoy, array $params = []): array
    {
        if (!$emp) throw new \InvalidArgumentException('Empleado no encontrado');
        $n = $this->nombreCompleto($emp);
        $ci = $emp['ci'] . ' ' . ($emp['ci_expedido'] ?? 'LP');
        $cargo = $emp['cargo'] ?? '';
        $area = $emp['area'] ?? '';
        $fi = $this->fechaTexto($emp['fecha_inicio'] ?? date('Y-m-d'));
        $ag = $emp['agencia'] ?? 'Oficina Central';
        $E = self::EMPRESA; $G = self::GERENTE;

        $c = <<<HTML
<h2>CERTIFICADO DE TRABAJO</h2>

<p>{$E}, con NIT 1024717022, entidad regulada por la Autoridad de Supervisión del Sistema Financiero (ASFI), por medio de su Gerencia General,</p>

<h3 style="text-align:center">CERTIFICA:</h3>

<p>Que <strong>{$n}</strong>, portador de la Cédula de Identidad Nro. <strong>{$ci}</strong>, presta servicios en esta empresa desde el <strong>{$fi}</strong> hasta la fecha, desempeñando el cargo de <strong>{$cargo}</strong> en el área de <strong>{$area}</strong>, en la agencia/oficina de <strong>{$ag}</strong>.</p>

<p>Se extiende el presente certificado a solicitud del interesado, para los fines que estime convenientes, conforme al Art. 16 del Decreto Reglamentario de la Ley General del Trabajo.</p>

<p>La Paz, {$hoy}.</p>

<div class="firmas">
<div class="firma-bloque">
<div class="firma-linea"></div>
<p><strong>{$G}</strong></p>
<p>GERENTE GENERAL</p>
<p>{$E}</p>
<p>NIT 1024717022</p>
</div>
</div>
HTML;

        return $this->result('certificado_trabajo', 'CERTIFICADO DE TRABAJO', $n, date('Y-m-d'), $c, 'Art. 16 DR-LGT');
    }

    private function cartaReferencia(?array $emp, string $hoy, array $params = []): array
    {
        if (!$emp) throw new \InvalidArgumentException('Empleado no encontrado');
        $n = $this->nombreCompleto($emp);
        $ci = $emp['ci'] . ' ' . ($emp['ci_expedido'] ?? 'LP');
        $cargo = $emp['cargo'] ?? '';
        $area = $emp['area'] ?? '';
        $fi = $this->fechaTexto($emp['fecha_inicio'] ?? date('Y-m-d'));
        $E = self::EMPRESA; $G = self::GERENTE;

        $c = <<<HTML
<h2>CARTA DE REFERENCIA LABORAL</h2>

<p>A quien corresponda:</p>

<p>Por medio de la presente, {$E} (NIT 1024717022), entidad regulada por ASFI, hace constar que <strong>{$n}</strong>, con C.I. <strong>{$ci}</strong>, forma parte de nuestro equipo de trabajo desde el <strong>{$fi}</strong>, desempeñando el cargo de <strong>{$cargo}</strong> en el área de <strong>{$area}</strong>.</p>

<p>Durante su permanencia en la empresa, ha demostrado responsabilidad, compromiso y profesionalismo en el cumplimiento de sus funciones, manteniendo una conducta intachable y apego a las normas institucionales y regulatorias.</p>

<p>Se extiende la presente carta de referencia laboral a solicitud del interesado, para los fines que estime convenientes.</p>

<p>La Paz, {$hoy}.</p>

<div class="firmas">
<div class="firma-bloque">
<div class="firma-linea"></div>
<p><strong>{$G}</strong></p>
<p>GERENTE GENERAL</p>
<p>{$E}</p>
</div>
</div>
HTML;

        return $this->result('carta_referencia', 'CARTA DE REFERENCIA LABORAL', $n, date('Y-m-d'), $c, '');
    }
}
