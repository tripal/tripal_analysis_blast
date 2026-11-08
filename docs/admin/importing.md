
# Importing BLAST analyses

!!! warning "Under Development"

    This documentation is still being developed as this module is being upgraded.

Performing the BLAST search
---------------------------

xxx

Adding a BLAST Database
-----------------------

A BLAST analysis involves running the NCBI blast program using a set of sequences,
and looking for matches in some database. A database is simply a collection of
multiple sequences. The collection can be relatively small, or it can be enormous
such as the NCBI nr and ExPASy SwissProt databases.

The first step is to create a record in chado referencing the BLAST database.
In Tripal, creating a database is done by a site administrator by navigating to 
**Tripal → Tripal Terms → Manage External Databases**
and then clicking on **Add a new database**.

As an example, suppose we have a genome assembly with gene prediction protein sequences,
and we performed a `blastp` analysis of those predictions to the ExPASy SwissProt database.

Use these values for adding the SwssProt database:

  | Field Name | Value |
  |------------|-------|
  | External Database Name | ExPASy Swiss-Prot |
  | Database Description | A curated protein sequence database which strives to provide a high level of annotation, a minimal level of redundancy and high level of integration with other databases |
  | Database URL | https://expasy.org/sprot/ |
  | Database URL prefix | https://www.uniprot.org/uniprot/ |

Adding a Database cross-reference record
----------------------------------------

Organizations such as NCBI or ExPASy contain many different blast databases, and different versions of those databases.
We want to create a specific cross-reference to the database and version that we used for our analysis.
Navigate to
**Tripal → Tripal Terms → Manage Database Cross References**
and then clicking on **Add a new database cross reference**.

  | Field Name | Value |
  |------------|-------|
  | External Database Name | ExPASy Swiss-Prot |
  | External Database Accession | Release 2026_03 |
  | External Database Accession Version | leave blank |
  | External Database Accession Description | leave blank |

Adding an Analysis record
-------------------------

We need a way to describe to a site user how the BLAST analyses were performed.
We do so using the "Analysis" content type.
Navigate to **Tripal → Content → + Add Tripal Content**
then select **Analysis**

  | Field Name | Value |
  |------------|-------|
  | Name | Blast analysis of the Tripalus genome assembly to Swiss-Prot 2026_03 |
  | Description | UniProtKB Release 2026_03 consists of 150,006,383 entries |
  | Software Name | blastp |
  | Software Version | 2.17.0+ |
  | Data Source Name | |
  | Data Source Version | |
  | Data Source URI | optional, the next item will provide the link to Swiss-Prot |
  | Database Reference Annotations | Select the ExPASy Swiss-Prot database, then Release 2026_03 |
  | Relationship | You should link to a separate analysis describing your genome assembly or gene prediction here |

Import the BLAST XML results
----------------------------
First navigate to the importer form **Tripal → Data Loaders → Chado BLAST XML results loader** and the importer page will be presented. The first part is where you upload your xml file.
There are multiple methods for providing the results file: via an upload interface,
specifying a remote URL, or a file path that is local to the server.
Most likely, you will upload or provide a remote URL.
importer_1.png

In the next section, 
Upload your XML file here.
Select the analysis you created.
Select the database you created.

