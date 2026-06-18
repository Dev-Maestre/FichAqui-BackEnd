<?php

namespace Database\Seeders;

use App\Models\CatalogoProduto;
use App\Models\Categoria;
use App\Models\VariantTemplate;
use Illuminate\Database\Seeder;

class CatalogSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->categorias() as $categoria) {
            Categoria::query()->updateOrCreate(['id' => $categoria['id']], $categoria);
        }

        foreach ($this->produtos() as $produto) {
            $variantes = $produto['variant_templates'];
            unset($produto['variant_templates']);

            CatalogoProduto::query()->updateOrCreate(['id' => $produto['id']], $produto);

            foreach ($variantes as $variante) {
                VariantTemplate::query()->updateOrCreate(
                    ['id' => "{$produto['id']}-{$variante['slug']}"],
                    [
                        'catalogo_produto_id' => $produto['id'],
                        'slug' => $variante['slug'],
                        'label' => $variante['label'],
                    ]
                );
            }
        }
    }

    /**
     * @return list<array{id: string, name: string, icon: string, color: string}>
     */
    private function categorias(): array
    {
        return [
            ['id' => 'comidas', 'name' => 'Comidas', 'icon' => 'UtensilsCrossed', 'color' => '#ef4444'],
            ['id' => 'doces', 'name' => 'Doces', 'icon' => 'Candy', 'color' => '#ec4899'],
            ['id' => 'bebidas', 'name' => 'Bebidas', 'icon' => 'GlassWater', 'color' => '#3b82f6'],
            ['id' => 'jogos', 'name' => 'Jogos', 'icon' => 'Gamepad2', 'color' => '#22c55e'],
            ['id' => 'brincadeiras', 'name' => 'Brincadeiras', 'icon' => 'PartyPopper', 'color' => '#f59e0b'],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function produtos(): array
    {
        return [
            $this->produto('pastel', 'Pastel', 'Pastel crocante frito na hora, recheado a sua escolha', 'comidas', 'pastel', 'Mais vendido', [
                ['slug' => 'carne', 'label' => 'Carne'],
                ['slug' => 'queijo', 'label' => 'Queijo'],
            ]),
            $this->produto('milho-verde', 'Milho Verde', 'Espiga de milho fresquinho com manteiga', 'comidas', 'milho-verde', 'Tradicional', [
                ['slug' => 'unidade', 'label' => 'Unidade'],
            ]),
            $this->produto('cachorro-quente', 'Cachorro Quente', 'Pao, salsicha, molho, batata palha e muito mais', 'comidas', 'cachorro-quente', null, [
                ['slug' => 'unidade', 'label' => 'Unidade'],
            ]),
            $this->produto('espetinho-carne', 'Espetinho de Carne', 'Espetinho grelhado na hora, bem temperado', 'comidas', 'espetinho-carne', 'Popular', [
                ['slug' => 'unidade', 'label' => 'Unidade'],
            ]),
            $this->produto('caldo-verde', 'Caldo Verde', 'Caldo quentinho de couve com linguica', 'comidas', 'caldo-verde', null, [
                ['slug' => 'copo', 'label' => 'Copo'],
            ]),
            $this->produto('maca-amor', 'Maca do Amor', 'Maca coberta com calda vermelha crocante', 'doces', 'maca-amor', 'Classico', [
                ['slug' => 'unidade', 'label' => 'Unidade'],
            ]),
            $this->produto('canjica', 'Canjica', 'Canjica cremosa com canela e coco', 'doces', 'canjica', null, [
                ['slug' => 'copo', 'label' => 'Copo'],
            ]),
            $this->produto('pacoca', 'Pacoca', 'Doce de amendoim tradicional', 'doces', 'pacoca', null, [
                ['slug' => 'unidade', 'label' => 'Unidade'],
            ]),
            $this->produto('pe-de-moleque', 'Pe de Moleque', 'Rapadura com amendoim crocante', 'doces', 'pe-de-moleque', null, [
                ['slug' => 'unidade', 'label' => 'Unidade'],
            ]),
            $this->produto('cocada', 'Cocada', 'Doce de coco caramelizado', 'doces', 'cocada', null, [
                ['slug' => 'unidade', 'label' => 'Unidade'],
            ]),
            $this->produto('quentao', 'Quentao', 'Bebida quente com gengibre e especiarias', 'bebidas', 'quentao', 'Esquenta!', [
                ['slug' => 'copo', 'label' => 'Copo'],
            ]),
            $this->produto('vinho-quente', 'Vinho Quente', 'Vinho temperado com canela e cravo', 'bebidas', 'vinho-quente', null, [
                ['slug' => 'copo', 'label' => 'Copo'],
            ]),
            $this->produto('refrigerante', 'Refrigerante', 'Lata 350ml gelada', 'bebidas', 'refrigerante', null, [
                ['slug' => 'coca', 'label' => 'Coca-Cola'],
                ['slug' => 'guarana', 'label' => 'Guarana'],
                ['slug' => 'fanta', 'label' => 'Fanta Laranja'],
            ]),
            $this->produto('agua-mineral', 'Agua Mineral', 'Garrafa 500ml', 'bebidas', 'agua-mineral', null, [
                ['slug' => 'garrafa', 'label' => 'Garrafa'],
            ]),
            $this->produto('suco-natural', 'Suco Natural', 'Copo 300ml feito na hora', 'bebidas', 'suco-natural', null, [
                ['slug' => 'laranja', 'label' => 'Laranja'],
                ['slug' => 'limao', 'label' => 'Limao'],
            ]),
            $this->produto('pescaria', 'Pescaria', 'Pesque um peixe e ganhe um premio!', 'jogos', 'pescaria', 'Diversao', [
                ['slug' => 'jogada', 'label' => 'Jogada'],
            ]),
            $this->produto('argolas', 'Argolas', 'Acerte as argolas e ganhe brindes', 'jogos', 'argolas', null, [
                ['slug' => 'jogada', 'label' => 'Jogada'],
            ]),
            $this->produto('tiro-ao-alvo', 'Tiro ao Alvo', 'Teste sua pontaria!', 'jogos', 'tiro-ao-alvo', null, [
                ['slug' => 'jogada', 'label' => 'Jogada'],
            ]),
            $this->produto('bingo', 'Cartela de Bingo', 'Concorra a premios incriveis!', 'brincadeiras', 'bingo', 'Premios!', [
                ['slug' => 'cartela', 'label' => 'Cartela'],
            ]),
            $this->produto('correio-elegante', 'Correio Elegante', 'Envie uma mensagem secreta para alguem especial', 'brincadeiras', 'correio-elegante', 'Romantico', [
                ['slug' => 'mensagem', 'label' => 'Mensagem'],
            ]),
            $this->produto('quadrilha', 'Quadrilha', 'Participe da danca tradicional', 'brincadeiras', 'quadrilha', 'Gratis', [
                ['slug' => 'participacao', 'label' => 'Participacao'],
            ]),
            $this->produto('peru-assado', 'Peru Assado', 'Fatia generosa de peru assado com temperos natalinos', 'comidas', 'peru-assado', 'Especial', [
                ['slug' => 'fatia', 'label' => 'Fatia'],
            ]),
            $this->produto('panetone', 'Panetone', 'Fatia de panetone com frutas cristalizadas', 'doces', 'panetone', null, [
                ['slug' => 'fatia', 'label' => 'Fatia'],
            ]),
            $this->produto('rabanada', 'Rabanada', 'Rabanada crocante com canela e acucar', 'doces', 'rabanada', null, [
                ['slug' => 'unidade', 'label' => 'Unidade'],
            ]),
            $this->produto('chocolate-quente', 'Chocolate Quente', 'Chocolate cremoso com marshmallow', 'bebidas', 'chocolate-quente', 'Quentinho', [
                ['slug' => 'copo', 'label' => 'Copo'],
            ]),
            $this->produto('vinho-natal', 'Vinho Quente', 'Vinho temperado com especiarias natalinas', 'bebidas', 'vinho-natal', null, [
                ['slug' => 'copo', 'label' => 'Copo'],
            ]),
            $this->produto('sopa', 'Sopa de Mandioca', 'Sopa cremosa servida no copo', 'comidas', 'sopa', null, [
                ['slug' => 'copo', 'label' => 'Copo'],
            ]),
            $this->produto('item-boas-vindas', 'Item de boas-vindas', 'Adicione mais itens pelo painel administrativo', 'comidas', 'item-boas-vindas', null, [
                ['slug' => 'unidade', 'label' => 'Unidade'],
            ]),
        ];
    }

    /**
     * @param  list<array{slug: string, label: string}>  $variantTemplates
     * @return array<string, mixed>
     */
    private function produto(
        string $id,
        string $name,
        string $description,
        string $categoriaId,
        string $image,
        ?string $badge,
        array $variantTemplates,
    ): array {
        return [
            'id' => $id,
            'categoria_id' => $categoriaId,
            'name' => $name,
            'description' => $description,
            'image' => $image,
            'badge' => $badge,
            'variant_templates' => $variantTemplates,
        ];
    }
}
